<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\RunRegistry;

use Orisai\Scheduler\RunRegistry\ActiveRun;
use Orisai\Scheduler\RunRegistry\FileRunRegistry;
use PHPUnit\Framework\TestCase;
use function array_map;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getmypid;
use function glob;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function time;
use function touch;
use function uniqid;
use function unlink;
use const JSON_THROW_ON_ERROR;

final class FileRunRegistryTest extends TestCase
{

	private string $directory;

	protected function setUp(): void
	{
		$this->directory = sys_get_temp_dir() . '/scheduler-registry-test-' . uniqid();
	}

	protected function tearDown(): void
	{
		if (!is_dir($this->directory)) {
			return;
		}

		$files = glob($this->directory . '/*');
		if ($files !== false) {
			foreach ($files as $file) {
				unlink($file);
			}
		}

		rmdir($this->directory);
	}

	private function createJson(?int $processStartTime): string
	{
		return json_encode([
			'pid' => getmypid(),
			'processStartTime' => $processStartTime,
			'startTimestamp' => time(),
		], JSON_THROW_ON_ERROR);
	}

	public function testRegisterAndDeregister(): void
	{
		$registry = new FileRunRegistry($this->directory);

		self::assertSame([], $registry->getActiveRuns());

		$registry->register('run-1', getmypid());
		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('run-1', $runs[0]->getId());

		$registry->register('run-2', getmypid());
		$runs = $registry->getActiveRuns();
		self::assertCount(2, $runs);
		$runIds = array_map(static fn (ActiveRun $r): string => $r->getId(), $runs);
		self::assertContains('run-1', $runIds);
		self::assertContains('run-2', $runIds);

		$registry->deregister('run-1');
		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('run-2', $runs[0]->getId());

		$registry->deregister('run-2');
		self::assertSame([], $registry->getActiveRuns());
	}

	public function testDeregisterNonexistent(): void
	{
		$registry = new FileRunRegistry($this->directory);
		$registry->deregister('nonexistent');

		self::assertSame([], $registry->getActiveRuns());
	}

	public function testDirectoryCreatedOnRegister(): void
	{
		$registry = new FileRunRegistry($this->directory);
		self::assertDirectoryDoesNotExist($this->directory);

		$registry->register('run-1', getmypid());
		self::assertDirectoryExists($this->directory);
		self::assertFileExists($this->directory . '/run-1.run');

		$registry->deregister('run-1');
		self::assertFileDoesNotExist($this->directory . '/run-1.run');
	}

	public function testStaleCleanup(): void
	{
		$registry = new FileRunRegistry($this->directory, 1);

		$registry->register('fresh-run', getmypid());

		// Simulate stale run file without processStartTime - triggers time-based fallback
		$staleFile = $this->directory . '/stale-run.run';
		file_put_contents(
			$staleFile,
			$this->createJson(null),
		);
		touch($staleFile, time() - 3_600);

		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('fresh-run', $runs[0]->getId());
		self::assertFileDoesNotExist($staleFile);

		$registry->deregister('fresh-run');
	}

	public function testStaleNotRemovedWithinThreshold(): void
	{
		$registry = new FileRunRegistry($this->directory, 3_600);

		$registry->register('run-1', getmypid());

		self::assertNotFalse(getmypid());

		// Simulate run file without processStartTime (e.g. macOS) - triggers time-based fallback
		$recentFile = $this->directory . '/recent-run.run';
		file_put_contents(
			$recentFile,
			$this->createJson(null),
		);
		touch($recentFile, time() - 10);

		$runs = $registry->getActiveRuns();
		self::assertCount(2, $runs);
		$runIds = array_map(static fn (ActiveRun $r): string => $r->getId(), $runs);
		self::assertContains('run-1', $runIds);
		self::assertContains('recent-run', $runIds);
		self::assertFileExists($recentFile);

		$registry->deregister('run-1');
		unlink($recentFile);
	}

	public function testStaleRemovedBeyondThreshold(): void
	{
		// 2 second threshold
		$registry = new FileRunRegistry($this->directory, 2);

		$registry->register('fresh-run', getmypid());

		// Simulate run file without processStartTime - triggers time-based fallback
		$staleFile = $this->directory . '/old-run.run';
		file_put_contents(
			$staleFile,
			$this->createJson(null),
		);
		touch($staleFile, time() - 5);

		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('fresh-run', $runs[0]->getId());
		self::assertFileDoesNotExist($staleFile);

		$registry->deregister('fresh-run');
	}

	public function testGetActiveRunsEmptyDirectory(): void
	{
		$registry = new FileRunRegistry('/nonexistent/directory');
		self::assertSame([], $registry->getActiveRuns());
	}

	public function testDefaultStaleThreshold(): void
	{
		$registry = new FileRunRegistry($this->directory);
		$registry->register('run-1', getmypid());

		// Simulate run file without processStartTime - triggers time-based fallback
		$file = $this->directory . '/not-stale.run';
		file_put_contents(
			$file,
			$this->createJson(null),
		);

		// File modified 30 minutes ago - within default 1h threshold
		touch($file, time() - 1_800);

		$runs = $registry->getActiveRuns();
		self::assertCount(2, $runs);

		// File modified 2 hours ago - beyond default 1h threshold
		touch($file, time() - 7_200);

		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('run-1', $runs[0]->getId());
		self::assertFileDoesNotExist($file);

		$registry->deregister('run-1');
	}

	public function testDeadProcessCleanedUp(): void
	{
		if (!function_exists('posix_kill')) {
			self::markTestSkipped('posix extension is required for PID-based cleanup');
		}

		$registry = new FileRunRegistry($this->directory);

		// PID 2147483647 almost certainly doesn't exist
		$registry->register('dead-run', 2_147_483_647);
		self::assertFileExists($this->directory . '/dead-run.run');

		$runs = $registry->getActiveRuns();
		self::assertSame([], $runs);
		self::assertFileDoesNotExist($this->directory . '/dead-run.run');
	}

	public function testAliveProcessKept(): void
	{
		$registry = new FileRunRegistry($this->directory);

		$registry->register('alive-run', getmypid());

		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('alive-run', $runs[0]->getId());
		self::assertSame(getmypid(), $runs[0]->getPid());
		self::assertNotNull($runs[0]->getStartTimestamp());

		$registry->deregister('alive-run');
	}

	public function testEmptyFileCleanedUp(): void
	{
		$registry = new FileRunRegistry($this->directory);
		$registry->register('valid', getmypid());

		$emptyFile = $this->directory . '/empty.run';
		file_put_contents($emptyFile, '');

		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('valid', $runs[0]->getId());
		self::assertFileDoesNotExist($emptyFile);

		$registry->deregister('valid');
	}

	public function testInvalidJsonCleanedUp(): void
	{
		$registry = new FileRunRegistry($this->directory);
		$registry->register('valid', getmypid());

		$invalidFile = $this->directory . '/invalid.run';
		file_put_contents($invalidFile, 'not json');

		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertFileDoesNotExist($invalidFile);

		$registry->deregister('valid');
	}

	public function testMissingPidCleanedUp(): void
	{
		$registry = new FileRunRegistry($this->directory);

		$file = $this->directory . '/no-pid.run';
		mkdir($this->directory, 0_777, true);
		file_put_contents($file, json_encode([
			'startTimestamp' => time(),
			'processStartTime' => null,
		], JSON_THROW_ON_ERROR));

		$runs = $registry->getActiveRuns();
		self::assertSame([], $runs);
		self::assertFileDoesNotExist($file);
	}

	public function testWrongPidTypeCleanedUp(): void
	{
		$registry = new FileRunRegistry($this->directory);

		$file = $this->directory . '/wrong-pid.run';
		mkdir($this->directory, 0_777, true);
		file_put_contents($file, json_encode([
			'pid' => 'not-int',
			'startTimestamp' => time(),
			'processStartTime' => null,
		], JSON_THROW_ON_ERROR));

		$runs = $registry->getActiveRuns();
		self::assertSame([], $runs);
		self::assertFileDoesNotExist($file);
	}

	public function testMissingProcessStartTimeKeyCleanedUp(): void
	{
		$registry = new FileRunRegistry($this->directory);

		$file = $this->directory . '/no-key.run';
		mkdir($this->directory, 0_777, true);
		file_put_contents($file, json_encode([
			'pid' => getmypid(),
			'startTimestamp' => time(),
		], JSON_THROW_ON_ERROR));

		$runs = $registry->getActiveRuns();
		self::assertSame([], $runs);
		self::assertFileDoesNotExist($file);
	}

	public function testProcessStartTimeStoredOnRegister(): void
	{
		$registry = new FileRunRegistry($this->directory);
		$registry->register('run-1', getmypid());

		$content = file_get_contents($this->directory . '/run-1.run');
		self::assertIsString($content);
		$data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

		self::assertSame(getmypid(), $data['pid']);
		self::assertArrayHasKey('processStartTime', $data);

		// On Linux with /proc, processStartTime should be a positive integer
		if (file_exists('/proc/self/stat')) {
			self::assertIsInt($data['processStartTime']);
			self::assertGreaterThan(0, $data['processStartTime']);
		} else {
			self::assertNull($data['processStartTime']);
		}

		$registry->deregister('run-1');
	}

	public function testPidReuseDetectedByProcessStartTime(): void
	{
		if (!file_exists('/proc/self/stat')) {
			self::markTestSkipped('/proc is required for process start time detection');
		}

		$registry = new FileRunRegistry($this->directory);
		$registry->register('run-1', getmypid());

		// Tamper with the stored processStartTime to simulate PID reuse -
		// the process is alive but its start time doesn't match.
		$file = $this->directory . '/run-1.run';
		$content = file_get_contents($file);
		self::assertIsString($content);
		$data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
		$data['processStartTime'] = 1; // wrong start time
		file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));

		$runs = $registry->getActiveRuns();
		self::assertSame([], $runs);
		self::assertFileDoesNotExist($file);
	}

	public function testRegisterCreatesDirectoryRecursively(): void
	{
		$nested = $this->directory . '/sub/dir';
		$registry = new FileRunRegistry($nested);

		$registry->register('run-1', getmypid());
		self::assertDirectoryExists($nested);
		self::assertFileExists($nested . '/run-1.run');

		$registry->deregister('run-1');
		rmdir($nested);
		rmdir($this->directory . '/sub');
	}

}
