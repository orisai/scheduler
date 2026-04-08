<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use Orisai\Scheduler\RunRegistry\ActiveRun;
use Orisai\Scheduler\Status\ActivityStatus;
use PHPUnit\Framework\TestCase;

final class ActivityStatusTest extends TestCase
{

	public function testNotInMaintenance(): void
	{
		$status = new ActivityStatus(false, []);

		self::assertFalse($status->isMaintenanceEnabled());
		self::assertSame([], $status->getActiveRuns());
		self::assertFalse($status->isReadyForShutdown());
	}

	public function testMaintenanceActiveNoRuns(): void
	{
		$status = new ActivityStatus(true, []);

		self::assertTrue($status->isMaintenanceEnabled());
		self::assertSame([], $status->getActiveRuns());
		self::assertTrue($status->isReadyForShutdown());
	}

	public function testMaintenanceActiveWithRuns(): void
	{
		$run1 = new ActiveRun('run-1', 123);
		$run2 = new ActiveRun('run-2', 456);
		$status = new ActivityStatus(true, [$run1, $run2]);

		self::assertTrue($status->isMaintenanceEnabled());
		$runs = $status->getActiveRuns();
		self::assertCount(2, $runs);
		self::assertSame('run-1', $runs[0]->getId());
		self::assertSame('run-2', $runs[1]->getId());
		self::assertFalse($status->isReadyForShutdown());
	}

	public function testNotMaintenanceWithRuns(): void
	{
		$run1 = new ActiveRun('run-1', 123);
		$status = new ActivityStatus(false, [$run1]);

		self::assertFalse($status->isMaintenanceEnabled());
		$runs = $status->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('run-1', $runs[0]->getId());
		self::assertFalse($status->isReadyForShutdown());
	}

	public function testNullMaintenance(): void
	{
		$status = new ActivityStatus(null, []);

		self::assertNull($status->isMaintenanceEnabled());
		self::assertSame([], $status->getActiveRuns());
		self::assertFalse($status->isReadyForShutdown());
	}

}
