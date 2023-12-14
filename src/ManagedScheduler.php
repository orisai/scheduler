<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Closure;
use Cron\CronExpression;
use Generator;
use Orisai\Clock\Adapter\ClockAdapterFactory;
use Orisai\Clock\Clock;
use Orisai\Clock\SystemClock;
use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Exceptions\Message;
use Orisai\Scheduler\Exception\JobFailure;
use Orisai\Scheduler\Executor\BasicJobExecutor;
use Orisai\Scheduler\Executor\JobExecutor;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobLock;
use Orisai\Scheduler\Manager\JobManager;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunParameters;
use Orisai\Scheduler\Status\RunSummary;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Throwable;
use function iterator_to_array;

class ManagedScheduler implements Scheduler
{

	private JobManager $jobManager;

	/** @var Closure(Throwable, JobInfo, JobResult): (void)|null */
	private ?Closure $errorHandler;

	private LockFactory $lockFactory;

	private JobExecutor $executor;

	private Clock $clock;

	/** @var list<Closure(JobInfo): void> */
	private array $beforeJob = [];

	/** @var list<Closure(JobInfo, JobResult): void> */
	private array $afterJob = [];

	/**
	 * @param Closure(Throwable, JobInfo, JobResult): (void)|null $errorHandler
	 */
	public function __construct(
		JobManager $jobManager,
		?Closure $errorHandler = null,
		?LockFactory $lockFactory = null,
		?JobExecutor $executor = null,
		?ClockInterface $clock = null
	)
	{
		$this->jobManager = $jobManager;
		$this->errorHandler = $errorHandler;
		$this->lockFactory = $lockFactory ?? new LockFactory(new InMemoryStore());
		$this->clock = ClockAdapterFactory::create($clock ?? new SystemClock());

		$this->executor = $executor ?? new BasicJobExecutor(
			$this->clock,
			$this->jobManager,
			fn ($id, Job $job, CronExpression $expression, int $second): array => $this->runInternal(
				$id,
				$job,
				$expression,
				$second,
			),
		);
	}

	public function getJobSchedules(): array
	{
		return $this->jobManager->getJobSchedules();
	}

	public function runJob($id, bool $force = true, ?RunParameters $parameters = null): ?JobSummary
	{
		$jobSchedule = $this->jobManager->getJobSchedule($id);
		$parameters ??= new RunParameters(0);

		if ($jobSchedule === null) {
			$message = Message::create()
				->withContext("Running job with ID '$id'")
				->withProblem('Job is not registered by scheduler.')
				->with(
					'Tip',
					"Inspect keys in 'Scheduler->getScheduledJobs()' or run command 'scheduler:list' to find correct job ID.",
				);

			throw InvalidArgument::create()
				->withMessage($message);
		}

		$expression = $jobSchedule->getExpression();

		// Intentionally ignores repeat after seconds
		if (!$force && !$expression->isDue($this->clock->now())) {
			return null;
		}

		[$summary, $throwable] = $this->runInternal($id, $jobSchedule->getJob(), $expression, $parameters->getSecond());

		if ($throwable !== null) {
			throw JobFailure::create($summary, $throwable);
		}

		return $summary;
	}

	public function runPromise(): Generator
	{
		$runStart = $this->clock->now();
		$ids = [];
		foreach ($this->jobManager->getExpressions() as $id => $expression) {
			if ($expression->isDue($runStart)) {
				$ids[] = $id;
			}
		}

		return $this->executor->runJobs($ids, $runStart);
	}

	public function run(): RunSummary
	{
		$generator = $this->runPromise();
		// Forces generator to execute
		iterator_to_array($generator);

		return $generator->getReturn();
	}

	/**
	 * @param string|int $id
	 * @param int<0, max> $second
	 * @return array{JobSummary, Throwable|null}
	 */
	private function runInternal($id, Job $job, CronExpression $expression, int $second): array
	{
		$info = new JobInfo(
			$id,
			$job->getName(),
			$expression->getExpression(),
			$second,
			$this->clock->now(),
		);

		$lock = $this->lockFactory->createLock("Orisai.Scheduler.Job/$id");

		if (!$lock->acquire()) {
			return [
				new JobSummary(
					$info,
					new JobResult($expression, $info->getStart(), JobResultState::skip()),
				),
				null,
			];
		}

		$throwable = null;
		try {
			foreach ($this->beforeJob as $cb) {
				$cb($info);
			}

			try {
				$job->run(new JobLock($lock));
			} catch (Throwable $throwable) {
				// Handled bellow
			}

			$result = new JobResult(
				$expression,
				$this->clock->now(),
				$throwable === null ? JobResultState::done() : JobResultState::fail(),
			);

			foreach ($this->afterJob as $cb) {
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

	/**
	 * @param Closure(JobInfo): void $callback
	 */
	public function addBeforeJobCallback(Closure $callback): void
	{
		$this->beforeJob[] = $callback;
	}

	/**
	 * @param Closure(JobInfo, JobResult): void $callback
	 */
	public function addAfterJobCallback(Closure $callback): void
	{
		$this->afterJob[] = $callback;
	}

}
