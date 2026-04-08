<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Doubles;

use Orisai\Scheduler\Maintenance\MaintenanceChecker;
use function file_exists;

final class FileExistsMaintenanceChecker implements MaintenanceChecker
{

	private string $filePath;

	public function __construct(string $filePath)
	{
		$this->filePath = $filePath;
	}

	public function isMaintenance(): bool
	{
		return file_exists($this->filePath);
	}

}
