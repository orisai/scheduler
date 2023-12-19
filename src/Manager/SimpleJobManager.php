<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Manager;

use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobSchedule;

final class SimpleJobManager implements JobManager
{

	/** @var array<int|string, JobSchedule> */
	private array $jobSchedules = [];

	/**
	 * @param int<0, 30> $repeatAfterSeconds
	 */
	public function addJob(
		Job $job,
		CronExpression $expression,
		?string $id = null,
		int $repeatAfterSeconds = 0,
		?DateTimeZone $timeZone = null
	): void
	{
		$jobSchedule = JobSchedule::create($job, $expression, $repeatAfterSeconds, $timeZone);

		if ($id === null) {
			$this->jobSchedules[] = $jobSchedule;
		} else {
			$this->jobSchedules[$id] = $jobSchedule;
		}
	}

	/**
	 * @param Closure(): Job $jobConstructor
	 * @param int<0, 30>     $repeatAfterSeconds
	 */
	public function addLazyJob(
		Closure $jobConstructor,
		CronExpression $expression,
		?string $id = null,
		int $repeatAfterSeconds = 0,
		?DateTimeZone $timeZone = null
	): void
	{
		$jobSchedule = JobSchedule::createLazy($jobConstructor, $expression, $repeatAfterSeconds, $timeZone);

		if ($id === null) {
			$this->jobSchedules[] = $jobSchedule;
		} else {
			$this->jobSchedules[$id] = $jobSchedule;
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

}
