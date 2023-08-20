<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Manager;

use Closure;
use Cron\CronExpression;
use Orisai\Scheduler\Job\Job;

final class CallbackJobManager implements JobManager
{

	/** @var array<int|string, Closure(): Job> */
	private array $jobs = [];

	/** @var array<int|string, CronExpression> */
	private array $expressions = [];

	/**
	 * @param Closure(): Job $jobCtor
	 */
	public function addJob(Closure $jobCtor, CronExpression $expression, ?string $id = null): void
	{
		if ($id === null) {
			$this->jobs[] = $jobCtor;
			$this->expressions[] = $expression;
		} else {
			$this->jobs[$id] = $jobCtor;
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
			$job(),
			$this->expressions[$id],
		];
	}

	public function getScheduledJobs(): array
	{
		$pairs = [];
		foreach ($this->jobs as $id => $job) {
			$pairs[$id] = [
				$job(),
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
