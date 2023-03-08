<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use Throwable;

final class JobResult
{

	private ?Throwable $throwable;

	public function __construct(?Throwable $throwable)
	{
		$this->throwable = $throwable;
	}

	public function getThrowable(): ?Throwable
	{
		return $this->throwable;
	}

}
