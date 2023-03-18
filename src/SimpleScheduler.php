<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Closure;
use Cron\CronExpression;
use Orisai\Clock\SystemClock;
use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Exceptions\Message;
use Orisai\Scheduler\Exception\JobFailure;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobLock;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\RunSummary;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Throwable;

final class SimpleScheduler implements Scheduler
{

	/** @var Closure(Throwable, JobInfo, JobResult): (void)|null */
	private ?Closure $errorHandler;

	private LockFactory $lockFactory;

	private ClockInterface $clock;

	/** @var array<int|string, array{Job, CronExpression}> */
	private array $jobs = [];

	/** @var list<Closure(JobInfo): void> */
	private array $beforeJob = [];

	/** @var list<Closure(JobInfo, JobResult): void> */
	private array $afterJob = [];

	/**
	 * @param Closure(Throwable, JobInfo, JobResult): (void)|null $errorHandler
	 */
	public function __construct(
		?Closure $errorHandler = null,
		?LockFactory $lockFactory = null,
		?ClockInterface $clock = null
	)
	{
		$this->errorHandler = $errorHandler;
		$this->lockFactory = $lockFactory ?? new LockFactory(new InMemoryStore());
		$this->clock = $clock ?? new SystemClock();
	}

	public function getJobs(): array
	{
		return $this->jobs;
	}

	public function addJob(Job $job, CronExpression $expression, ?string $key = null): void
	{
		$key === null
			? $this->jobs[] = [$job, $expression]
			: $this->jobs[$key] = [$job, $expression];
	}

	public function runJob($id, bool $force = true): ?array
	{
		$jobSet = $this->jobs[$id] ?? null;

		if ($jobSet === null) {
			$message = Message::create()
				->withContext("Running job with ID '$id'")
				->withProblem('Job is not registered by scheduler.')
				->with(
					'Tip',
					"Inspect keys in 'Scheduler->getJobs()' or run command 'scheduler:list' to find correct job ID.",
				);

			throw InvalidArgument::create()
				->withMessage($message);
		}

		if (!$force && !$jobSet[1]->isDue($this->clock->now())) {
			return null;
		}

		[$info, $result, $throwable] = $this->runInternal($id, $jobSet[0], $jobSet[1]);

		if ($throwable !== null) {
			throw JobFailure::create($info, $result, $throwable);
		}

		return [$info, $result];
	}

	public function run(): RunSummary
	{
		$runStart = $this->clock->now();
		$jobs = [];
		foreach ($this->jobs as [$job, $expression]) {
			if ($expression->isDue($runStart)) {
				$jobs[] = [$job, $expression];
			}
		}

		$summaryJobs = [];
		$suppressed = [];
		foreach ($jobs as $i => [$job, $expression]) {
			$jobSummary = $this->runInternal($i, $job, $expression);

			$throwable = $jobSummary[2];
			unset($jobSummary[2]);
			$summaryJobs[] = $jobSummary;

			if ($throwable !== null) {
				$suppressed[] = $throwable;
			}
		}

		$summary = new RunSummary($summaryJobs);

		if ($suppressed !== []) {
			throw RunFailure::create($summary, $suppressed);
		}

		return $summary;
	}

	/**
	 * @param string|int $id
	 * @return array{JobInfo, JobResult, Throwable|null}
	 */
	private function runInternal($id, Job $job, CronExpression $expression): array
	{
		$info = new JobInfo(
			$job->getName(),
			$expression->getExpression(),
			$this->clock->now(),
		);

		$lock = $this->lockFactory->createLock(
			"Orisai.Scheduler.Job/{$info->getExpression()}-{$info->getName()}-$id",
		);

		if (!$lock->acquire()) {
			return [
				$info,
				new JobResult($expression, $info->getStart(), JobResultState::skip()),
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

		return [$info, $result, $throwable];
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
