<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Manager;

use Cron\CronExpression;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobSchedule;

final class SimpleJobManager implements JobManager
{

	/** @var array<int|string, JobSchedule> */
	private array $jobSchedules = [];

	/** @var array<int|string, CronExpression> */
	private array $expressions = [];

	/**
	 * @param int<0, 30> $repeatAfterSeconds
	 */
	public function addJob(Job $job, CronExpression $expression, ?string $id = null, int $repeatAfterSeconds = 0): void
	{
		$jobSchedule = new JobSchedule($job, $expression, $repeatAfterSeconds);

		if ($id === null) {
			$this->jobSchedules[] = $jobSchedule;
			$this->expressions[] = $expression;
		} else {
			$this->jobSchedules[$id] = $jobSchedule;
			$this->expressions[$id] = $expression;
		}
	}

	public function getJobSchedule($id): ?JobSchedule
	{
		return $this->jobSchedules[$id] ?? null;
	}

	public function getJobSchedules(): array
	{
		return $this->jobSchedules;
	}

	public function getExpressions(): array
	{
		return $this->expressions;
	}

}
