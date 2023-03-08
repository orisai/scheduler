<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Job;

use Closure;

final class CallbackJob implements Job
{

	/** @var Closure(): void */
	private Closure $callback;

	/**
	 * @param Closure(): void $callback
	 */
	public function __construct(Closure $callback)
	{
		$this->callback = $callback;
	}

	public function run(): void
	{
		($this->callback)();
	}

}
