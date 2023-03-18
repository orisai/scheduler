<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

final class RunSummary
{

	/** @var list<JobSummary> */
	private array $jobs;

	/**
	 * @param list<JobSummary> $jobs
	 */
	public function __construct(array $jobs)
	{
		$this->jobs = $jobs;
	}

	/**
	 * @return list<JobSummary>
	 */
	public function getJobs(): array
	{
		return $this->jobs;
	}

}
