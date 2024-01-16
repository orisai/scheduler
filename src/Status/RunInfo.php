<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use DateTimeImmutable;

final class RunInfo
{

	private DateTimeImmutable $start;

	/** @var list<PlannedJobInfo> */
	private array $jobInfos;

	/**
	 * @param list<PlannedJobInfo> $jobInfos
	 */
	public function __construct(DateTimeImmutable $start, array $jobInfos)
	{
		$this->start = $start;
		$this->jobInfos = $jobInfos;
	}

	public function getStart(): DateTimeImmutable
	{
		return $this->start;
	}

	/**
	 * @return list<PlannedJobInfo>
	 */
	public function getJobInfos(): array
	{
		return $this->jobInfos;
	}

}
