<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Maintenance;

interface MaintenanceChecker
{

	public function isMaintenance(): bool;

}
