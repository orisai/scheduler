<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Maintenance;

use Orisai\Scheduler\Maintenance\MaintenanceManager;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\Scheduler\Doubles\FileExistsMaintenanceChecker;
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class MaintenanceManagerTest extends TestCase
{

	public function testNotInMaintenance(): void
	{
		$checker = new FileExistsMaintenanceChecker(sys_get_temp_dir() . '/nonexistent-' . uniqid() . '.flag');
		$manager = new MaintenanceManager($checker);

		self::assertFalse($manager->isMaintenance());
		self::assertSame(30, $manager->getGracePeriodSeconds());
	}

	public function testInMaintenance(): void
	{
		$file = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($file, '');
		$checker = new FileExistsMaintenanceChecker($file);
		$manager = new MaintenanceManager($checker);

		self::assertTrue($manager->isMaintenance());

		unlink($file);
	}

	public function testCustomGracePeriod(): void
	{
		$checker = new FileExistsMaintenanceChecker(sys_get_temp_dir() . '/nonexistent-' . uniqid() . '.flag');
		$manager = new MaintenanceManager($checker, 60);

		self::assertSame(60, $manager->getGracePeriodSeconds());
	}

}
