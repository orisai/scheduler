<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use DateTimeImmutable;
use Throwable;

final class JobResult
{

	private DateTimeImmutable $end;

	private ?Throwable $throwable;

	public function __construct(DateTimeImmutable $end, ?Throwable $throwable)
	{
		$this->end = $end;
		$this->throwable = $throwable;
	}

	public function getEnd(): DateTimeImmutable
	{
		return $this->end;
	}

	public function getThrowable(): ?Throwable
	{
		return $this->throwable;
	}

}
