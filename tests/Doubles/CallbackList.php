<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Doubles;

use Closure;
use Error;
use Exception;

final class CallbackList
{

	public function job1(): void
	{
		// Noop
	}

	public function job2(): void
	{
		// Noop
	}

	public static function staticJob(): void
	{
		// Noop
	}

	/**
	 * @return Closure(): void
	 */
	public function getClosure(): Closure
	{
		return static function (): void {
			// Noop
		};
	}

	public function exceptionJob(): void
	{
		throw new Exception('test');
	}

	public function errorJob(): void
	{
		throw new Error('test');
	}

	public function __invoke(): void
	{
		// Noop
	}

}
