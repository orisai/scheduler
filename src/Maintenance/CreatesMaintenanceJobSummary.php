<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Maintenance;

use DateTimeImmutable;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;

/**
 * @internal
 */
trait CreatesMaintenanceJobSummary
{

	/**
	 * @param int|string $id
	 * @param int<0, max> $runSecond
	 */
	private function createMaintenanceJobSummary(
		$id,
		JobSchedule $jobSchedule,
		int $runSecond,
		DateTimeImmutable $runStart
	): JobSummary
	{
		$job = $jobSchedule->getJob();
		$timezone = $jobSchedule->getTimeZone();
		$now = $timezone !== null
			? $this->clock->now()->setTimezone($timezone)
			: $this->clock->now();

		$info = new JobInfo(
			$id,
			$job->getName(),
			$jobSchedule->getExpression()->getExpression(),
			$jobSchedule->getRepeatAfterSeconds(),
			$runSecond,
			$timezone !== null ? $runStart->setTimezone($timezone) : $runStart,
			$timezone,
			false,
		);

		$result = new JobResult(
			$jobSchedule->getExpression(),
			$now,
			JobResultState::maintenance(),
		);

		return new JobSummary($info, $result);
	}

}
