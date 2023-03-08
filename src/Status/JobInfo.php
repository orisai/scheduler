<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use DateTimeImmutable;

final class JobInfo
{

	private DateTimeImmutable $start;

	public function __construct(DateTimeImmutable $start)
	{
		$this->start = $start;
	}

	public function getStart(): DateTimeImmutable
	{
		return $this->start;
	}

}
