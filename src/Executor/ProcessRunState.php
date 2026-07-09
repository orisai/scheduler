<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use DateTimeImmutable;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Status\JobSummary;
use Throwable;

/**
 * @internal
 */
final class ProcessRunState
{

	/** @var array<int, array<int|string, JobSchedule>> */
	public array $jobSchedulesBySecond;

	public DateTimeImmutable $runStart;

	/** @var array<int, SubprocessExecutionState> */
	public array $jobExecutions = [];

	/** @var list<JobSummary> */
	public array $jobSummaries = [];

	/** @var list<Throwable> */
	public array $suppressedExceptions = [];

	public bool $maintenanceActive = false;

	public ?float $shutdownDetectedAt = null;

	public float $lastShutdownCheckAt = 0.0;

	public float $lastRefreshAt = 0.0;

	/** @var int<-1, max> */
	public int $lastExecutedSecond = -1;

	/**
	 * @param array<int, array<int|string, JobSchedule>> $jobSchedulesBySecond
	 */
	public function __construct(array $jobSchedulesBySecond, DateTimeImmutable $runStart)
	{
		$this->jobSchedulesBySecond = $jobSchedulesBySecond;
		$this->runStart = $runStart;
	}

}
