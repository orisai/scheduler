<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

final class RunSummary
{

	/** @var list<array{JobInfo, JobResult}> */
	private array $jobs;

	/**
	 * @param list<array{JobInfo, JobResult}> $jobs
	 */
	public function __construct(array $jobs)
	{
		$this->jobs = $jobs;
	}

	/**
	 * @return list<array{JobInfo, JobResult}>
	 */
	public function getJobs(): array
	{
		return $this->jobs;
	}

}
