<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Doubles;

use Orisai\Scheduler\Maintenance\MaintenanceChecker;

/**
 * Returns false for the first N calls, then true.
 * Useful for testing mid-run maintenance detection.
 */
final class DelayedMaintenanceChecker implements MaintenanceChecker
{

	private int $callCount = 0;

	private int $activateAfterCalls;

	public function __construct(int $activateAfterCalls = 1)
	{
		$this->activateAfterCalls = $activateAfterCalls;
	}

	public function isMaintenance(): bool
	{
		return ++$this->callCount > $this->activateAfterCalls;
	}

}
