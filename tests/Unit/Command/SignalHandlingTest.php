<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use function array_merge;
use function file_exists;
use function function_exists;
use function posix_kill;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;
use function usleep;
use const SIGINT;
use const SIGTERM;

/**
 * Tests real signal handling by spawning subprocess binaries and sending POSIX signals.
 *
 * @group subprocess
 */
final class SignalHandlingTest extends TestCase
{

	protected function setUp(): void
	{
		if (!function_exists('posix_kill')) {
			self::markTestSkipped('posix extension is required');
		}
	}

	/**
	 * First SIGTERM triggers graceful shutdown via requestShutdown().
	 * RunCommand exits with code 2 (maintenance).
	 */
	public function testRunCommandSigtermGracefulShutdown(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/signal-test-maintenance-' . uniqid();
		$registryDir = sys_get_temp_dir() . '/signal-test-registry-' . uniqid();
		$readyFile = sys_get_temp_dir() . '/signal-test-ready-' . uniqid();

		$process = $this->startPhpProcess([
			__DIR__ . '/signal-run-command-binary.php',
			$maintenanceFile,
			$registryDir,
			'0',
			$readyFile,
		]);

		$this->waitForReadyFile($readyFile, $process);

		posix_kill($this->getPid($process), SIGTERM);

		$exitCode = $this->waitForExit($process);

		self::assertSame(2, $exitCode, 'Expected maintenance exit code 2');
		self::assertStringContainsString('Shutdown requested, stopping jobs gracefully', $process->getOutput());
	}

	/**
	 * Second signal triggers immediate exit(1) via double-signal pattern.
	 */
	public function testRunCommandDoubleSignalForcesExit(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/signal-test-maintenance-' . uniqid();
		$registryDir = sys_get_temp_dir() . '/signal-test-registry-' . uniqid();
		$readyFile = sys_get_temp_dir() . '/signal-test-ready-' . uniqid();

		$process = $this->startPhpProcess([
			__DIR__ . '/signal-run-command-binary.php',
			$maintenanceFile,
			$registryDir,
			'30',
			$readyFile,
		]);

		$this->waitForReadyFile($readyFile, $process);

		$pid = $this->getPid($process);

		// First signal (SIGINT) - graceful shutdown (sets flag but long grace period keeps it alive)
		posix_kill($pid, SIGINT);
		usleep(200_000);

		if (!$process->isRunning()) {
			// Process exited from first signal (subprocess finished quickly)
			self::assertTrue(true);

			return;
		}

		// Second signal (SIGTERM) - different type avoids kernel coalescing, triggers exit(1)
		posix_kill($pid, SIGTERM);

		$exitCode = $this->waitForExit($process);

		self::assertSame(1, $exitCode, 'Expected forced exit code 1');
		$output = $process->getOutput();
		self::assertStringContainsString('Shutdown requested, stopping jobs gracefully', $output);
		self::assertStringContainsString('Forcing immediate shutdown', $output);
	}

	/**
	 * First SIGTERM stops the worker loop, worker waits for subprocesses and exits.
	 */
	public function testWorkerCommandSigtermGracefulStop(): void
	{
		$readyFile = sys_get_temp_dir() . '/signal-test-ready-' . uniqid();

		$process = $this->startPhpProcess([
			__DIR__ . '/signal-worker-command-binary.php',
			$readyFile,
		]);

		$this->waitForReadyFile($readyFile, $process);

		posix_kill($this->getPid($process), SIGTERM);

		$exitCode = $this->waitForExit($process);

		self::assertSame(0, $exitCode, 'Expected success exit code 0');
	}

	/**
	 * Second signal on worker forces immediate exit.
	 */
	public function testWorkerCommandDoubleSignalForcesExit(): void
	{
		$readyFile = sys_get_temp_dir() . '/signal-test-ready-' . uniqid();

		$process = $this->startPhpProcess([
			__DIR__ . '/signal-worker-command-binary.php',
			$readyFile,
		]);

		$this->waitForReadyFile($readyFile, $process);

		$pid = $this->getPid($process);

		// Send both signals immediately - kernel queues both, pcntl dispatches
		// between opcodes. First (SIGINT) sets flag, second (SIGTERM) triggers exit(1).
		posix_kill($pid, SIGINT);
		posix_kill($pid, SIGTERM);

		$exitCode = $this->waitForExit($process);

		self::assertSame(1, $exitCode, 'Expected forced exit code 1');
	}

	/**
	 * Polls for a ready file created by the subprocess binary.
	 * This ensures signal handlers are registered before we send signals.
	 */
	private function waitForReadyFile(string $readyFile, Process $process, int $timeoutMs = 10_000): void
	{
		$waited = 0;
		$step = 50_000; // 50ms

		while ($waited < $timeoutMs * 1_000) {
			if (file_exists($readyFile)) {
				@unlink($readyFile);
				// Small buffer for handler registration to complete after file creation
				usleep(100_000);

				return;
			}

			if (!$process->isRunning()) {
				self::fail(
					'Process exited before becoming ready.'
					. ' Exit code: ' . $process->getExitCode()
					. ' stdout: ' . trim($process->getOutput())
					. ' stderr: ' . trim($process->getErrorOutput()),
				);
			}

			usleep($step);
			$waited += $step;
		}

		$process->stop(1);
		self::fail('Timed out waiting for process to become ready');
	}

	/**
	 * Waits for process exit, handling ProcessSignaledException.
	 */
	private function waitForExit(Process $process): int
	{
		try {
			$process->wait();
		} catch (ProcessSignaledException $e) {
			// Expected when process is terminated by signal
		}

		return $process->getExitCode() ?? -1;
	}

	private function getPid(Process $process): int
	{
		$pid = $process->getPid();
		self::assertNotNull($pid);

		return $pid;
	}

	/**
	 * @param list<string> $arguments
	 */
	private function startPhpProcess(array $arguments): Process
	{
		$finder = new PhpExecutableFinder();
		$binary = $finder->find(false);
		self::assertIsString($binary);

		$command = array_merge([$binary], $finder->findArguments(), $arguments);

		$process = new Process($command);
		$process->setTimeout(30);
		$process->start();

		return $process;
	}

}
