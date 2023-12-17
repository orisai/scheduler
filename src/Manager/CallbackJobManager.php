<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Manager;

use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobSchedule;

final class CallbackJobManager implements JobManager
{

	/** @var array<int|string, JobSchedule> */
	private array $jobSchedules = [];

	/**
	 * @param Closure(): Job $jobCtor
	 * @param int<0, 30> $repeatAfterSeconds
	 */
	public function addJob(
		Closure $jobCtor,
		CronExpression $expression,
		?string $id = null,
		int $repeatAfterSeconds = 0,
		?DateTimeZone $timeZone = null
	): void
	{
		$jobSchedule = JobSchedule::createLazy($jobCtor, $expression, $repeatAfterSeconds, $timeZone);

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
