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
use Orisai\Scheduler\Executor\JobRunner;
use Orisai\Scheduler\Executor\ShutdownCheck;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Maintenance\MaintenanceJobSummaryFactory;
use Orisai\Scheduler\Maintenance\MaintenanceManager;
use Orisai\Scheduler\Manager\JobManager;
use Orisai\Scheduler\RunRegistry\RunRegistry;
use Orisai\Scheduler\Status\ActivityStatus;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobSummary;
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

	private JobManager $jobManager;

	private JobExecutor $executor;

	private Clock $clock;

	private MaintenanceJobSummaryFactory $maintenanceSummaryFactory;

	private ?MaintenanceManager $maintenanceManager;

	private ?RunRegistry $runRegistry;

	private SchedulerCallbacks $callbacks;

	private JobRunner $jobRunner;

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
		$this->clock = ClockAdapterFactory::create($clock ?? new SystemClock());
		$this->maintenanceSummaryFactory = new MaintenanceJobSummaryFactory($this->clock);
		$this->callbacks = new SchedulerCallbacks();

		$this->jobRunner = new JobRunner(
			$lockFactory ?? new LockFactory(new InMemoryStore()),
			$this->clock,
			$logger ?? new NullLogger(),
			$errorHandler,
		);

		$this->executor = $executor ?? new BasicJobExecutor($this->clock, $this->jobRunner);

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

	public function runJob(
		$id,
		bool $force = true,
		?RunParameters $parameters = null,
		?Closure $onJobStarted = null,
		?Closure $onJobFinished = null
	): ?JobSummary
	{
		$this->jobRunner->releaseExpiredMinuteLocks();

		$jobSchedule = $this->jobManager->getJobSchedule($id);
		// Explicit RunParameters signals subprocess context — parent's runPromise() handles
		// all callback firing, so only the caller's emitters are passed as hooks.
		// Subprocesses also trust the parent's earlier maintenance check rather than
		// re-checking (which would surface a narrow mid-spawn race as a RunFailure).
		$isSubprocess = $parameters !== null;
		$parameters ??= new RunParameters(0, true);

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

		// $force only controls the due check — intentionally ignores repeat after seconds.
		if (!$force && !$expression->isDue($jobDueTime)) {
			return null;
		}

		// Maintenance applies to every direct call regardless of $force. Only subprocesses
		// bypass, because the parent already checked before spawning. When maintenance is
		// active, return a synthetic `maintenance`-state summary so forced callers still
		// get a JobSummary (matching the conditional return type).
		if (!$isSubprocess && $this->maintenanceManager !== null && $this->maintenanceManager->isMaintenance()) {
			return $this->maintenanceSummaryFactory->create(
				$id,
				$jobSchedule,
				$parameters->getSecond(),
				$this->clock->now(),
			);
		}

		if ($isSubprocess) {
			// Parent's runPromise() fires registered callbacks — only the caller's
			// emitters run in the subprocess.
			$onStarted = $onJobStarted;
			$onFinished = $onJobFinished;
		} else {
			$onStarted = function (JobInfo $info) use ($onJobStarted): void {
				$this->callbacks->fireJobStarted($info);

				if ($onJobStarted !== null) {
					$onJobStarted($info);
				}
			};
			$onFinished = function (JobInfo $info, JobResult $result) use ($onJobFinished): void {
				$this->callbacks->fireJobFinished($info, $result);

				if ($onJobFinished !== null) {
					$onJobFinished($info, $result);
				}
			};
		}

		try {
			[$summary, $throwable] = $this->jobRunner->run(
				$id,
				$jobSchedule,
				$parameters,
				$onStarted,
				$onFinished,
			);
		} finally {
			$this->jobRunner->releaseExpiredMinuteLocks();
		}

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
		$this->jobRunner->releaseExpiredMinuteLocks();

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

				yield from $this->callbacks->wrapGenerator($generator);

				return $generator->getReturn();
			}

			$shutdownCheck = $this->createShutdownCheck($runId);

			$generator = $this->executor->runJobs(
				$this->groupJobSchedulesBySecond($jobSchedules),
				$runStart,
				fn () => $this->callbacks->fireBeforeRun($runStart, $jobSchedules),
				fn (RunSummary $runSummary) => $this->callbacks->fireAfterRun($runSummary),
				$shutdownCheck,
				// Called by executors when a job starts — ProcessJobExecutor via the subprocess
				// `started` event, BasicJobExecutor via JobRunner's onStarted hook. Fires
				// beforeJobCallbacks with the reported JobInfo.
				fn ($id, JobSchedule $jobSchedule, int $runSecond, JobInfo $info) => $this->callbacks->fireJobStarted(
					$info,
				),
			);

			yield from $this->callbacks->wrapGenerator($generator);

			return $generator->getReturn();
		} finally {
			if ($this->runRegistry !== null) {
				$this->runRegistry->deregister($runId);
			}

			$this->jobRunner->releaseExpiredMinuteLocks();
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
		$this->callbacks->fireBeforeRun($runStart, $jobSchedules);

		$jobSummaries = [];
		foreach ($jobSchedules as $id => $jobSchedule) {
			yield $jobSummaries[] = $this->maintenanceSummaryFactory->create($id, $jobSchedule, 0, $runStart);
		}

		$runSummary = new RunSummary($runStart, $this->clock->now(), $jobSummaries, true);

		$this->callbacks->fireAfterRun($runSummary);

		return $runSummary;
	}

	public function run(): RunSummary
	{
		$generator = $this->runPromise();
		// Forces generator to execute
		iterator_to_array($generator);

		return $generator->getReturn();
	}

	/**
	 * @param Closure(JobInfo, JobResult): void $callback
	 * @param-later-invoked-callable $callback
	 *
	 * @deprecated Use addAfterJobCallback() and check $result->getState() === JobResultState::lock(). Will be removed in v3.0.
	 */
	public function addLockedJobCallback(Closure $callback): void
	{
		$this->callbacks->addLockedJob($callback);
	}

	/**
	 * @param Closure(JobInfo): void $callback
	 * @param-later-invoked-callable $callback
	 */
	public function addBeforeJobCallback(Closure $callback): void
	{
		$this->callbacks->addBeforeJob($callback);
	}

	/**
	 * @param Closure(JobInfo, JobResult): void $callback
	 * @param-later-invoked-callable $callback
	 */
	public function addAfterJobCallback(Closure $callback): void
	{
		$this->callbacks->addAfterJob($callback);
	}

	/**
	 * @param Closure(RunInfo): void $callback
	 * @param-later-invoked-callable $callback
	 */
	public function addBeforeRunCallback(Closure $callback): void
	{
		$this->callbacks->addBeforeRun($callback);
	}

	/**
	 * @param Closure(RunSummary): void $callback
	 * @param-later-invoked-callable $callback
	 */
	public function addAfterRunCallback(Closure $callback): void
	{
		$this->callbacks->addAfterRun($callback);
	}

}
