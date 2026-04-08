<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Maintenance;

use PHPUnit\Framework\TestCase;
use Tests\Orisai\Scheduler\Doubles\FileExistsMaintenanceChecker;
use function sys_get_temp_dir;
use function tempnam;
use function touch;
use function unlink;

final class FileExistsMaintenanceCheckerTest extends TestCase
{

	public function testFileDoesNotExist(): void
	{
		$checker = new FileExistsMaintenanceChecker('/nonexistent/file.flag');
		self::assertFalse($checker->isMaintenance());
	}

	public function testFileExists(): void
	{
		$file = tempnam(sys_get_temp_dir(), 'maintenance-test-');
		self::assertIsString($file);
		touch($file);

		$checker = new FileExistsMaintenanceChecker($file);
		self::assertTrue($checker->isMaintenance());

		unlink($file);
		self::assertFalse($checker->isMaintenance());
	}

}
