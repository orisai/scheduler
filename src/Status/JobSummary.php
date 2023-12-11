<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

final class JobSummary
{

	private JobInfo $info;

	private JobResult $result;

	public function __construct(JobInfo $info, JobResult $result)
	{
		$this->info = $info;
		$this->result = $result;
	}

	public function getInfo(): JobInfo
	{
		return $this->info;
	}

	public function getResult(): JobResult
	{
		return $this->result;
	}

	/**
	 * @return array<mixed>
	 */
	public function toArray(): array
	{
		return [
			'info' => $this->info->toArray(),
			'result' => $this->result->toArray(),
		];
	}

}
