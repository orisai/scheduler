<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use Closure;

final class ShutdownCheck
{

	/** @var Closure(): bool */
	private Closure $check;

	private int $gracePeriodSeconds;

	/** @var Closure(): void */
	private Closure $refreshCallback;

	/**
	 * @param Closure(): bool $check
	 * @param Closure(): void $refreshCallback
	 */
	public function __construct(Closure $check, int $gracePeriodSeconds, Closure $refreshCallback)
	{
		$this->check = $check;
		$this->gracePeriodSeconds = $gracePeriodSeconds;
		$this->refreshCallback = $refreshCallback;
	}

	public function shouldShutdown(): bool
	{
		return ($this->check)();
	}

	public function getGracePeriodSeconds(): int
	{
		return $this->gracePeriodSeconds;
	}

	public function refresh(): void
	{
		($this->refreshCallback)();
	}

}
