<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Maintenance;

final class MaintenanceManager
{

	private MaintenanceChecker $checker;

	private int $gracePeriodSeconds;

	private bool $shutdownRequested = false;

	public function __construct(
		MaintenanceChecker $checker,
		int $gracePeriodSeconds = 30
	)
	{
		$this->checker = $checker;
		$this->gracePeriodSeconds = $gracePeriodSeconds;
	}

	public function isMaintenance(): bool
	{
		return $this->checker->isMaintenance();
	}

	public function requestShutdown(): void
	{
		$this->shutdownRequested = true;
	}

	public function isShutdownRequested(): bool
	{
		return $this->shutdownRequested;
	}

	public function getGracePeriodSeconds(): int
	{
		return $this->gracePeriodSeconds;
	}

}
