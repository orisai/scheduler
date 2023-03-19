<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Exception;

use Orisai\Exceptions\LogicalException;

final class JobProcessFailure extends LogicalException
{

	public static function create(): self
	{
		return new self();
	}

}
