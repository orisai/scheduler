<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Manager;

use Closure;
use Cron\CronExpression;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobSchedule;

final class CallbackJobManager implements JobManager
{

	/** @var array<int|string, Closure(): Job> */
	private array $jobs = [];

	/** @var array<int|string, CronExpression> */
	private array $expressions = [];

	/** @var array<int|string, int<0, 30>> */
	private array $repeat = [];

	/**
	 * @param Closure(): Job $jobCtor
	 * @param int<0, 30> $repeatAfterSeconds
	 */
	public function addJob(
		Closure $jobCtor,
		CronExpression $expression,
		?string $id = null,
		int $repeatAfterSeconds = 0
	): void
	{
		if ($id === null) {
			$this->jobs[] = $jobCtor;
			$this->expressions[] = $expression;
			$this->repeat[] = $repeatAfterSeconds;
		} else {
			$this->jobs[$id] = $jobCtor;
			$this->expressions[$id] = $expression;
			$this->repeat[$id] = $repeatAfterSeconds;
		}
	}

	public function getJobSchedule($id): ?JobSchedule
	{
		$job = $this->jobs[$id] ?? null;

		if ($job === null) {
			return null;
		}

		return new JobSchedule(
			$job(),
			$this->expressions[$id],
			$this->repeat[$id],
		);
	}

	public function getJobSchedules(): array
	{
		$scheduledJobs = [];
		foreach ($this->jobs as $id => $job) {
			$scheduledJobs[$id] = new JobSchedule(
				$job(),
				$this->expressions[$id],
				$this->repeat[$id],
			);
		}

		return $scheduledJobs;
	}

	public function getExpressions(): array
	{
		return $this->expressions;
	}

}
