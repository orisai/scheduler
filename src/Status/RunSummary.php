<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use DateTimeImmutable;

final class RunSummary
{

	private DateTimeImmutable $start;

	private DateTimeImmutable $end;

	/** @var list<JobSummary> */
	private array $jobSummaries;

	private bool $maintenanceActive;

	/**
	 * @param list<JobSummary> $jobSummaries
	 */
	public function __construct(
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		array $jobSummaries,
		bool $maintenanceActive = false
	)
	{
		$this->start = $start;
		$this->end = $end;
		$this->jobSummaries = $jobSummaries;
		$this->maintenanceActive = $maintenanceActive;
	}

	public function getStart(): DateTimeImmutable
	{
		return $this->start;
	}

	public function getEnd(): DateTimeImmutable
	{
		return $this->end;
	}

	/**
	 * @return list<JobSummary>
	 */
	public function getJobSummaries(): array
	{
		return $this->jobSummaries;
	}

	public function isMaintenanceActive(): bool
	{
		return $this->maintenanceActive;
	}

}
