<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Command;

use Orisai\Scheduler\Command\StatusCommand;
use Orisai\Scheduler\Maintenance\MaintenanceManager;
use Orisai\Scheduler\RunRegistry\FileRunRegistry;
use Orisai\Scheduler\RunRegistry\RunRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Orisai\Scheduler\Doubles\FileExistsMaintenanceChecker;
use function file_put_contents;
use function getmypid;
use function json_decode;
use function method_exists;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use const JSON_THROW_ON_ERROR;

final class StatusCommandTest extends TestCase
{

	public function testStatusNotInMaintenance(): void
	{
		$checker = new FileExistsMaintenanceChecker(sys_get_temp_dir() . '/nonexistent-' . uniqid());
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$tester = $this->createTester($registry, $manager);
		$tester->execute([]);

		$output = $tester->getDisplay();
		self::assertStringContainsString('INACTIVE', $output);
		self::assertStringContainsString('Active runs: 0', $output);
		self::assertStringContainsString('NO', $output);
		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
	}

	public function testStatusInMaintenanceNoRuns(): void
	{
		$file = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($file, '');

		$checker = new FileExistsMaintenanceChecker($file);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$tester = $this->createTester($registry, $manager);
		$tester->execute([]);

		$output = $tester->getDisplay();
		self::assertStringContainsString('ACTIVE', $output);
		self::assertStringContainsString('Active runs: 0', $output);
		self::assertStringContainsString('YES', $output);
		self::assertSame(Command::SUCCESS, $tester->getStatusCode());

		unlink($file);
	}

	public function testStatusInMaintenanceWithRuns(): void
	{
		$file = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($file, '');

		$checker = new FileExistsMaintenanceChecker($file);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$registry->register('1712345678-abc', getmypid());

		$tester = $this->createTester($registry, $manager);
		$tester->execute([]);

		$output = $tester->getDisplay();
		self::assertStringContainsString('Active runs: 1', $output);
		self::assertStringContainsString('1712345678-abc', $output);
		self::assertStringContainsString('NO', $output);
		self::assertSame(Command::SUCCESS, $tester->getStatusCode());

		$registry->deregister('1712345678-abc');
		unlink($file);
	}

	public function testStatusJson(): void
	{
		$file = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($file, '');

		$checker = new FileExistsMaintenanceChecker($file);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$registry->register('run-1', getmypid());

		$tester = $this->createTester($registry, $manager);
		$tester->execute(['--json' => true]);

		$data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
		self::assertTrue($data['maintenance']);
		self::assertCount(1, $data['activeRuns']);
		self::assertSame('run-1', $data['activeRuns'][0]['id']);
		self::assertFalse($data['readyForShutdown']);
		self::assertSame(Command::SUCCESS, $tester->getStatusCode());

		$registry->deregister('run-1');
		unlink($file);
	}

	public function testStatusJsonNotInMaintenance(): void
	{
		$checker = new FileExistsMaintenanceChecker(sys_get_temp_dir() . '/nonexistent-' . uniqid());
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$tester = $this->createTester($registry, $manager);
		$tester->execute(['--json' => true]);

		$data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
		self::assertFalse($data['maintenance']);
		self::assertSame([], $data['activeRuns']);
		self::assertFalse($data['readyForShutdown']);
		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
	}

	public function testFailWhenNotReadyReadyForShutdown(): void
	{
		$file = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($file, '');

		$checker = new FileExistsMaintenanceChecker($file);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$tester = $this->createTester($registry, $manager);
		$tester->execute(['--fail-when-not-ready-for-shutdown' => true]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());

		unlink($file);
	}

	public function testFailWhenNotReadyActiveRuns(): void
	{
		$file = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($file, '');

		$checker = new FileExistsMaintenanceChecker($file);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$registry->register('run-1', getmypid());

		$tester = $this->createTester($registry, $manager);
		$tester->execute(['--fail-when-not-ready-for-shutdown' => true]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());

		$registry->deregister('run-1');
		unlink($file);
	}

	public function testFailWhenNotReadyNoMaintenance(): void
	{
		$checker = new FileExistsMaintenanceChecker(sys_get_temp_dir() . '/nonexistent-' . uniqid());
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$tester = $this->createTester($registry, $manager);
		$tester->execute(['--fail-when-not-ready-for-shutdown' => true]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
	}

	public function testFailWhenNotReadyJsonReady(): void
	{
		$file = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($file, '');

		$checker = new FileExistsMaintenanceChecker($file);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$tester = $this->createTester($registry, $manager);
		$tester->execute(['--fail-when-not-ready-for-shutdown' => true, '--json' => true]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());

		unlink($file);
	}

	public function testFailWhenNotReadyJsonNotReady(): void
	{
		$checker = new FileExistsMaintenanceChecker(sys_get_temp_dir() . '/nonexistent-' . uniqid());
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$tester = $this->createTester($registry, $manager);
		$tester->execute(['--fail-when-not-ready-for-shutdown' => true, '--json' => true]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
	}

	public function testStatusWithoutMaintenanceManager(): void
	{
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$registry->register('run-1', getmypid());

		$tester = $this->createTester($registry);
		$tester->execute([]);

		$output = $tester->getDisplay();
		self::assertStringContainsString('UNAVAILABLE', $output);
		self::assertStringContainsString('Active runs: 1', $output);
		self::assertStringContainsString('run-1', $output);
		self::assertSame(Command::SUCCESS, $tester->getStatusCode());

		$registry->deregister('run-1');
	}

	public function testStatusJsonWithoutMaintenanceManager(): void
	{
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);

		$tester = $this->createTester($registry);
		$tester->execute(['--json' => true]);

		$data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
		self::assertNull($data['maintenance']);
		self::assertSame([], $data['activeRuns']);
		self::assertFalse($data['readyForShutdown']);
		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
	}

	public function testFailWhenNotReadyWithoutMaintenanceManager(): void
	{
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);

		$tester = $this->createTester($registry);
		$tester->execute(['--fail-when-not-ready-for-shutdown' => true]);

		// No maintenance manager → not ready for shutdown → FAILURE
		self::assertSame(Command::FAILURE, $tester->getStatusCode());
	}

	private function createTester(RunRegistry $registry, ?MaintenanceManager $manager = null): CommandTester
	{
		$command = new StatusCommand($registry, $manager);
		$app = new Application();
		if (method_exists($app, 'addCommand')) {
			$app->addCommand($command);
		} else {
			$app->add($command);
		}

		return new CommandTester($command);
	}

}
