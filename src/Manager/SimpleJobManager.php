<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Manager;

use Cron\CronExpression;
use Orisai\Scheduler\Job\Job;

final class SimpleJobManager implements JobManager
{

	/** @var array<int|string, Job> */
	private array $jobs = [];

	/** @var array<int|string, CronExpression> */
	private array $expressions = [];

	/** @var array<int|string, int<0, 30>> */
	private array $repeat = [];

	/**
	 * @param int<0, 30> $repeatAfterSeconds
	 */
	public function addJob(Job $job, CronExpression $expression, ?string $id = null, int $repeatAfterSeconds = 0): void
	{
		if ($id === null) {
			$this->jobs[] = $job;
			$this->expressions[] = $expression;
			$this->repeat[] = $repeatAfterSeconds;
		} else {
			$this->jobs[$id] = $job;
			$this->expressions[$id] = $expression;
			$this->repeat[$id] = $repeatAfterSeconds;
		}
	}

	public function getScheduledJob($id): ?array
	{
		$job = $this->jobs[$id] ?? null;

		if ($job === null) {
			return null;
		}

		return [
			$job,
			$this->expressions[$id],
			$this->repeat[$id],
		];
	}

	public function getScheduledJobs(): array
	{
		$scheduledJobs = [];
		foreach ($this->jobs as $id => $job) {
			$scheduledJobs[$id] = [
				$job,
				$this->expressions[$id],
				$this->repeat[$id],
			];
		}

		return $scheduledJobs;
	}

	public function getExpressions(): array
	{
		return $this->expressions;
	}

}
