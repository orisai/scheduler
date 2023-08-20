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

	public function addJob(Job $job, CronExpression $expression, ?string $id = null): void
	{
		if ($id === null) {
			$this->jobs[] = $job;
			$this->expressions[] = $expression;
		} else {
			$this->jobs[$id] = $job;
			$this->expressions[$id] = $expression;
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
		];
	}

	public function getScheduledJobs(): array
	{
		$pairs = [];
		foreach ($this->jobs as $id => $job) {
			$pairs[$id] = [
				$job,
				$this->expressions[$id],
			];
		}

		return $pairs;
	}

	public function getExpressions(): array
	{
		return $this->expressions;
	}

}
