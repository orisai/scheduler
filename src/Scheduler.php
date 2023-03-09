<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Closure;
use Cron\CronExpression;
use Orisai\Clock\SystemClock;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Psr\Clock\ClockInterface;
use Throwable;

final class Scheduler
{

	private ClockInterface $clock;

	/** @var list<array{Job, CronExpression}> */
	private array $jobs = [];

	/** @var list<Closure(JobInfo): void> */
	private array $beforeJob = [];

	/** @var list<Closure(JobInfo, JobResult): void> */
	private array $afterJob = [];

	public function __construct(?ClockInterface $clock = null)
	{
		$this->clock = $clock ?? new SystemClock();
	}

	public function addJob(Job $job, CronExpression $expression): void
	{
		$this->jobs[] = [$job, $expression];
	}

	public function run(): void
	{
		$runStart = $this->clock->now();
		$jobs = [];
		foreach ($this->jobs as [$job, $expression]) {
			if ($expression->isDue($runStart)) {
				$jobs[] = [$job, $expression];
			}
		}

		foreach ($jobs as [$job, $expression]) {
			$info = new JobInfo(
				$job->getName(),
				$expression->getExpression(),
				$this->clock->now(),
			);

			foreach ($this->beforeJob as $cb) {
				$cb($info);
			}

			$throwable = null;
			try {
				$job->run();
			} catch (Throwable $throwable) {
				// Handled bellow
			}

			$result = new JobResult($this->clock->now(), $throwable);

			foreach ($this->afterJob as $cb) {
				$cb($info, $result);
			}
		}
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
