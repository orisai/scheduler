<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use DateTimeImmutable;

final class RunSummary
{

	private DateTimeImmutable $start;

	private DateTimeImmutable $end;

	/** @var list<JobSummary> */
	private array $jobs;

	/**
	 * @param list<JobSummary> $jobs
	 */
	public function __construct(DateTimeImmutable $start, DateTimeImmutable $end, array $jobs)
	{
		$this->start = $start;
		$this->end = $end;
		$this->jobs = $jobs;
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
	public function getJobs(): array
	{
		return $this->jobs;
	}

}
