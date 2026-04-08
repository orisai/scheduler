<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Closure;
use DateTimeImmutable;
use Generator;
use Orisai\Clock\Adapter\ClockAdapterFactory;
use Orisai\Clock\Clock;
use Orisai\Clock\SystemClock;
use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Exceptions\Message;
use Orisai\Scheduler\Exception\JobFailure;
use Orisai\Scheduler\Executor\BasicJobExecutor;
use Orisai\Scheduler\Executor\JobExecutor;
use Orisai\Scheduler\Executor\ShutdownCheck;
use Orisai\Scheduler\Job\JobLock;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Maintenance\CreatesMaintenanceJobSummary;
use Orisai\Scheduler\Maintenance\MaintenanceManager;
use Orisai\Scheduler\Manager\JobManager;
use Orisai\Scheduler\RunRegistry\RunRegistry;
use Orisai\Scheduler\Status\ActivityStatus;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\PlannedJobInfo;
use Orisai\Scheduler\Status\RunInfo;
use Orisai\Scheduler\Status\RunParameters;
use Orisai\Scheduler\Status\RunSummary;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Throwable;
use function bin2hex;
use function getmypid;
use function iterator_to_array;
use function random_bytes;
use function time;

class ManagedScheduler implements Scheduler
{

	use CreatesMaintenanceJobSummary;

	private JobManager $jobManager;

	/** @var Closure(Throwable, JobInfo, JobResult): (void)|null */
	private ?Closure $errorHandler;

	private LockFactory $lockFactory;

	private JobExecutor $executor;

	private Clock $clock;

	private LoggerInterface $logger;

	private ?MaintenanceManager $maintenanceManager;

	private ?RunRegistry $runRegistry;

	/** @var list<Closure(JobInfo, JobResult): void> */
	private array $lockedJobCallbacks = [];

	/** @var list<Closure(JobInfo): void> */
	private array $beforeJobCallbacks = [];

	/** @var list<Closure(JobInfo, JobResult): void> */
	private array $afterJobCallbacks = [];

	/** @var list<Closure(RunInfo): void> */
	private array $beforeRunCallbacks = [];

	/** @var list<Closure(RunSummary): void> */
	private array $afterRunCallbacks = [];

	/**
	 * @param Closure(Throwable, JobInfo, JobResult): (void)|null $errorHandler
	 * @param-later-invoked-callable $errorHandler
	 */
	public function __construct(
		JobManager $jobManager,
		?Closure $errorHandler = null,
		?LockFactory $lockFactory = null,
		?JobExecutor $executor = null,
		?ClockInterface $clock = null,
		?LoggerInterface $logger = null,
		?MaintenanceManager $maintenanceManager = null,
		?RunRegistry $runRegistry = null
	)
	{
		$this->jobManager = $jobManager;
		$this->errorHandler = $errorHandler;
		$this->lockFactory = $lockFactory ?? new LockFactory(new InMemoryStore());
		$this->clock = ClockAdapterFactory::create($clock ?? new SystemClock());
		$this->logger = $logger ?? new NullLogger();

		$this->executor = $executor ?? new BasicJobExecutor(
			$this->clock,
			fn ($id, JobSchedule $jobSchedule, int $runSecond): array => $this->runInternal(
				$id,
				$jobSchedule,
				new RunParameters($runSecond, false),
			),
		);

		$this->maintenanceManager = $maintenanceManager;
		$this->runRegistry = $runRegistry;
	}

	public function getStatus(): ActivityStatus
	{
		$maintenance = $this->maintenanceManager !== null
			? $this->maintenanceManager->isMaintenance()
			: null;

		$activeRuns = $this->runRegistry !== null
			? $this->runRegistry->getActiveRuns()
			: [];

		return new ActivityStatus($maintenance, $activeRuns);
	}

	public function getJobSchedules(): array
	{
		return $this->jobManager->getJobSchedules();
	}

	public function runJob($id, bool $force = true, ?RunParameters $parameters = null): ?JobSummary
	{
		$jobSchedule = $this->jobManager->getJobSchedule($id);
		$parameters ??= new RunParameters(0, $force);

		if ($jobSchedule === null) {
			$message = Message::create()
				->withContext("Running job with ID '$id'")
				->withProblem('Job is not registered by scheduler.')
				->with(
					'Tip',
					"Inspect keys in 'Scheduler->getJobSchedules()' or run command 'scheduler:list' to find correct job ID.",
				);

			throw InvalidArgument::create()
				->withMessage($message);
		}

		$expression = $jobSchedule->getExpression();

		$timeZone = $jobSchedule->getTimeZone();
		$jobDueTime = $timeZone !== null
			? $this->clock->now()->setTimezone($timeZone)
			: $this->clock->now();

		// Intentionally ignores repeat after seconds
		if (!$force && !$expression->isDue($jobDueTime)) {
			return null;
		}

		[$summary, $throwable] = $this->runInternal($id, $jobSchedule, $parameters);

		if ($throwable !== null) {
			throw JobFailure::create($summary, $throwable);
		}

		return $summary;
	}

	/**
	 * @param array<int|string, JobSchedule> $jobSchedules
	 * @return array<int, array<int|string, JobSchedule>>
	 */
	private function groupJobSchedulesBySecond(array $jobSchedules): array
	{
		$scheduledJobsBySecond = [];
		foreach ($jobSchedules as $id => $jobSchedule) {
			$repeatAfterSeconds = $jobSchedule->getRepeatAfterSeconds();

			if ($repeatAfterSeconds === 0) {
				$scheduledJobsBySecond[0][$id] = $jobSchedule;
			} else {
				for ($second = 0; $second <= 59; $second += $repeatAfterSeconds) {
					$scheduledJobsBySecond[$second][$id] = $jobSchedule;
				}
			}
		}

		return $scheduledJobsBySecond;
	}

	public function runPromise(): Generator
	{
		$runStart = $this->clock->now();
		$runId = time() . '-' . bin2hex(random_bytes(3));

		if ($this->runRegistry !== null) {
			$pid = getmypid();
			$this->runRegistry->register($runId, $pid !== false ? $pid : 0);
		}

		try {
			$jobSchedules = [];
			foreach ($this->jobManager->getJobSchedules() as $id => $schedule) {
				$timeZone = $schedule->getTimeZone();
				$jobDueTime = $timeZone !== null
					? $runStart->setTimezone($timeZone)
					: $runStart;

				if ($schedule->getExpression()->isDue($jobDueTime)) {
					$jobSchedules[$id] = $schedule;
				}
			}

			// Check maintenance before starting any jobs
			if ($this->maintenanceManager !== null && $this->maintenanceManager->isMaintenance()) {
				$generator = $this->createMaintenanceRunSummary($runStart, $jobSchedules);

				yield from $generator;

				return $generator->getReturn();
			}

			$shutdownCheck = $this->createShutdownCheck($runId);

			$generator = $this->executor->runJobs(
				$this->groupJobSchedulesBySecond($jobSchedules),
				$runStart,
				$this->getBeforeRunCallback($runStart, $jobSchedules),
				$this->getAfterRunCallback(),
				$shutdownCheck,
			);

			yield from $generator;

			return $generator->getReturn();
		} finally {
			if ($this->runRegistry !== null) {
				$this->runRegistry->deregister($runId);
			}
		}
	}

	private function createShutdownCheck(string $runId): ?ShutdownCheck
	{
		if ($this->maintenanceManager === null) {
			return null;
		}

		$manager = $this->maintenanceManager;
		$registry = $this->runRegistry;

		return new ShutdownCheck(
			static fn (): bool => $manager->isShutdownRequested() || $manager->isMaintenance(),
			$manager->getGracePeriodSeconds(),
			static function () use ($registry, $runId): void {
				if ($registry !== null) {
					$registry->refresh($runId);
				}
			},
		);
	}

	/**
	 * @param array<int|string, JobSchedule> $jobSchedules
	 * @return Generator<int, JobSummary, void, RunSummary>
	 */
	private function createMaintenanceRunSummary(DateTimeImmutable $runStart, array $jobSchedules): Generator
	{
		$jobSummaries = [];
		foreach ($jobSchedules as $id => $jobSchedule) {
			yield $jobSummaries[] = $this->createMaintenanceJobSummary($id, $jobSchedule, 0, $runStart);
		}

		$summary = new RunSummary($runStart, $this->clock->now(), $jobSummaries, true);

		foreach ($this->afterRunCallbacks as $cb) {
			$cb($summary);
		}

		return $summary;
	}

	/**
	 * @param array<int|string, JobSchedule> $jobSchedules
	 * @return Closure(): void
	 */
	private function getBeforeRunCallback(DateTimeImmutable $runStart, array $jobSchedules): Closure
	{
		return function () use ($runStart, $jobSchedules): void {
			if ($this->beforeRunCallbacks === []) {
				return;
			}

			$jobInfos = [];
			foreach ($jobSchedules as $id => $jobSchedule) {
				$job = $jobSchedule->getJob();
				$timezone = $jobSchedule->getTimeZone();
				$jobStart = $timezone !== null
					? $runStart->setTimezone($timezone)
					: $runStart;
				$jobInfos[] = new PlannedJobInfo(
					$id,
					$job->getName(),
					$jobSchedule->getExpression()->getExpression(),
					$jobSchedule->getRepeatAfterSeconds(),
					$jobStart,
					$timezone,
				);
			}

			$info = new RunInfo($runStart, $jobInfos);

			foreach ($this->beforeRunCallbacks as $cb) {
				$cb($info);
			}
		};
	}

	/**
	 * @return Closure(RunSummary): void
	 */
	private function getAfterRunCallback(): Closure
	{
		return function (RunSummary $runSummary): void {
			foreach ($this->afterRunCallbacks as $cb) {
				$cb($runSummary);
			}
		};
	}

	public function run(): RunSummary
	{
		$generator = $this->runPromise();
		// Forces generator to execute
		iterator_to_array($generator);

		return $generator->getReturn();
	}

	/**
	 * @param string|int  $id
	 * @return array{JobSummary, Throwable|null}
	 */
	private function runInternal($id, JobSchedule $jobSchedule, RunParameters $runParameters): array
	{
		$job = $jobSchedule->getJob();
		$expression = $jobSchedule->getExpression();

		$info = new JobInfo(
			$id,
			$job->getName(),
			$expression->getExpression(),
			$jobSchedule->getRepeatAfterSeconds(),
			$runParameters->getSecond(),
			$this->getCurrentTime($jobSchedule),
			$jobSchedule->getTimeZone(),
			$runParameters->isForcedRun(),
		);

		$lock = $this->lockFactory->createLock("Orisai.Scheduler.Job/$id");

		if (!$lock->acquire()) {
			$result = new JobResult($expression, $info->getStart(), JobResultState::lock());

			foreach ($this->lockedJobCallbacks as $cb) {
				$cb($info, $result);
			}

			return [
				new JobSummary($info, $result),
				null,
			];
		}

		$throwable = null;
		try {
			foreach ($this->beforeJobCallbacks as $cb) {
				$cb($info);
			}

			try {
				$job->run(new JobLock($lock));
			} catch (Throwable $throwable) {
				// Handled bellow
			}

			if ($lock->isExpired()) {
				$this->logger->warning("Lock of job '$id' expired before the job finished.", [
					'id' => $id,
				]);
			}

			$result = new JobResult(
				$expression,
				$this->getCurrentTime($jobSchedule),
				$throwable === null ? JobResultState::done() : JobResultState::fail(),
			);

			foreach ($this->afterJobCallbacks as $cb) {
				$cb($info, $result);
			}

			if ($throwable !== null && $this->errorHandler !== null) {
				($this->errorHandler)($throwable, $info, $result);
				$throwable = null;
			}
		} finally {
			$lock->release();
		}

		return [
			new JobSummary($info, $result),
			$throwable,
		];
	}

	private function getCurrentTime(JobSchedule $schedule): DateTimeImmutable
	{
		$now = $this->clock->now();
		$timezone = $schedule->getTimeZone();

		return $timezone !== null
			? $now->setTimezone($timezone)
			: $now;
	}

	/**
	 * @param Closure(JobInfo, JobResult): void $callback
	 * @param-later-invoked-callable $callback
	 */
	public function addLockedJobCallback(Closure $callback): void
	{
		$this->lockedJobCallbacks[] = $callback;
	}

	/**
	 * @param Closure(JobInfo): void $callback
	 * @param-later-invoked-callable $callback
	 */
	public function addBeforeJobCallback(Closure $callback): void
	{
		$this->beforeJobCallbacks[] = $callback;
	}

	/**
	 * @param Closure(JobInfo, JobResult): void $callback
	 * @param-later-invoked-callable $callback
	 */
	public function addAfterJobCallback(Closure $callback): void
	{
		$this->afterJobCallbacks[] = $callback;
	}

	/**
	 * @param Closure(RunInfo): void $callback
	 * @param-later-invoked-callable $callback
	 */
	public function addBeforeRunCallback(Closure $callback): void
	{
		$this->beforeRunCallbacks[] = $callback;
	}

	/**
	 * @param Closure(RunSummary): void $callback
	 * @param-later-invoked-callable $callback
	 */
	public function addAfterRunCallback(Closure $callback): void
	{
		$this->afterRunCallbacks[] = $callback;
	}

}
