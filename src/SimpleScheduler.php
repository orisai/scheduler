<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Closure;
use Cron\CronExpression;
use Orisai\Clock\SystemClock;
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

	private LockFactory $lockFactory;

	private ClockInterface $clock;

	/** @var list<array{Job, CronExpression}> */
	private array $jobs = [];

	/** @var list<Closure(JobInfo): void> */
	private array $beforeJob = [];

	/** @var list<Closure(JobInfo, JobResult, Throwable|null): void> */
	private array $afterJob = [];

	public function __construct(?LockFactory $lockFactory = null, ?ClockInterface $clock = null)
	{
		$this->lockFactory = $lockFactory ?? new LockFactory(new InMemoryStore());
		$this->clock = $clock ?? new SystemClock();
	}

	public function getJobs(): array
	{
		return $this->jobs;
	}

	public function addJob(Job $job, CronExpression $expression): void
	{
		$this->jobs[] = [$job, $expression];
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
		foreach ($jobs as $i => [$job, $expression]) {
			$info = new JobInfo(
				$job->getName(),
				$expression->getExpression(),
				$this->clock->now(),
			);

			$lock = $this->lockFactory->createLock(
				"Orisai.Scheduler.Job/{$info->getExpression()}-{$info->getName()}-$i",
			);

			if (!$lock->acquire()) {
				$summaryJobs[] = [
					$info,
					new JobResult($expression, $info->getStart(), JobResultState::skip()),
				];

				continue;
			}

			try {
				foreach ($this->beforeJob as $cb) {
					$cb($info);
				}

				$throwable = null;
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
					$cb($info, $result, $throwable);
				}
			} finally {
				$lock->release();
			}

			$summaryJobs[] = [$info, $result];
		}

		return new RunSummary($summaryJobs);
	}

	/**
	 * @param Closure(JobInfo): void $callback
	 */
	public function addBeforeJobCallback(Closure $callback): void
	{
		$this->beforeJob[] = $callback;
	}

	/**
	 * @param Closure(JobInfo, JobResult, Throwable|null): void $callback
	 */
	public function addAfterJobCallback(Closure $callback): void
	{
		$this->afterJob[] = $callback;
	}

}
