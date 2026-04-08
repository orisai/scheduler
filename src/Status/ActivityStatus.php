<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use Orisai\Scheduler\RunRegistry\ActiveRun;

final class ActivityStatus
{

	private ?bool $maintenance;

	/** @var list<ActiveRun> */
	private array $activeRuns;

	/**
	 * @param list<ActiveRun> $activeRuns
	 */
	public function __construct(?bool $maintenance, array $activeRuns)
	{
		$this->maintenance = $maintenance;
		$this->activeRuns = $activeRuns;
	}

	/**
	 * Returns null when MaintenanceManager is not configured.
	 */
	public function isMaintenanceEnabled(): ?bool
	{
		return $this->maintenance;
	}

	/**
	 * @return list<ActiveRun>
	 */
	public function getActiveRuns(): array
	{
		return $this->activeRuns;
	}

	public function isReadyForShutdown(): bool
	{
		return $this->maintenance === true && $this->activeRuns === [];
	}

}
