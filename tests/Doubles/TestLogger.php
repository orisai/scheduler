<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Doubles;

use Psr\Log\AbstractLogger;
use Stringable;

final class TestLogger extends AbstractLogger
{

	/** @var list<mixed> */
	public array $logs = [];

	/**
	 * @param string|Stringable $message
	 * @param array<mixed> $context
	 */
	public function log($level, $message, array $context = []): void
	{
		$this->logs[] = [
			$level,
			$message,
			$context,
		];
	}

}
