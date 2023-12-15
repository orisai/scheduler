<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use DateTimeImmutable;
use Generator;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunSummary;

interface JobExecutor
{

	/**
	 * @param array<int, array<int|string, JobSchedule>> $jobSchedulesBySecond
	 * @return Generator<int, JobSummary, void, RunSummary>
	 * @throws RunFailure
	 */
	public function runJobs(array $jobSchedulesBySecond, DateTimeImmutable $runStart): Generator;

}
