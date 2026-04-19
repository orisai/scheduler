<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Maintenance;

use Cron\CronExpression;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Command\RunCommand;
use Orisai\Scheduler\Command\WorkerCommand;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Executor\ProcessJobExecutor;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Maintenance\MaintenanceManager;
use Orisai\Scheduler\ManagedScheduler;
use Orisai\Scheduler\Manager\SimpleJobManager;
use Orisai\Scheduler\RunRegistry\FileRunRegistry;
use Orisai\Scheduler\SimpleScheduler;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Orisai\Scheduler\Doubles\DelayedMaintenanceChecker;
use Tests\Orisai\Scheduler\Doubles\TestLogger;
use function function_exists;
use function fwrite;
use function getmypid;
use function posix_kill;
use function putenv;
use function sleep;
use function sys_get_temp_dir;
use function uniqid;
use const DIRECTORY_SEPARATOR;
use const SIGTERM;
use const STDERR;

final class SignalAndShutdownTest extends TestCase
{

	public function testRunCommandSignalHandlerRegistered(): void
	{
		// `DelayedMaintenanceChecker(0)` returns true on the first isMaintenance() call —
		// no filesystem dependency (avoids stat-cache / fs race flakiness on WSL2 / CI).
		$checker = new DelayedMaintenanceChecker(0);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);
		$scheduler = new SimpleScheduler(null, null, $executor, $clock, null, $manager, $registry);
		$scheduler->addJob(
			new CallbackJob(static function (): void {
			}),
			new CronExpression('* * * * *'),
		);

		$command = new RunCommand($scheduler, $clock, $manager);

		$tester = new CommandTester($command);
		putenv('COLUMNS=80');
		$tester->execute([]);

		self::assertSame(2, $tester->getStatusCode());
	}

	public function testRunCommandSignalHandlerNotRegisteredWithoutMaintenanceManager(): void
	{
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$command = new RunCommand($scheduler, $clock);

		$tester = new CommandTester($command);
		$tester->execute([]);

		self::assertSame(0, $tester->getStatusCode());
	}

	/**
	 * Sends real SIGTERM to the current process mid-execution via beforeRun callback.
	 * Verifies the handler closure calls requestShutdown() on MaintenanceManager.
	 */
	public function testRunCommandSignalHandlerCallbackTriggersShutdown(): void
	{
		if (!function_exists('posix_kill')) {
			self::markTestSkipped('posix extension is required');
		}

		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);
		$checker = new DelayedMaintenanceChecker(999);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$scheduler = new SimpleScheduler(null, null, $executor, $clock, null, $manager, $registry);
		$scheduler->addJob(
			new CallbackJob(static function (): void {
			}),
			new CronExpression('* * * * *'),
		);

		$command = new RunCommand($scheduler, $clock, $manager);

		// Send real SIGTERM to ourselves during execution.
		// The beforeRun callback fires after registerSignalHandlers() but before the executor loop.
		$scheduler->addBeforeRunCallback(static function (): void {
			posix_kill(getmypid(), SIGTERM);
		});

		$tester = new CommandTester($command);
		putenv('COLUMNS=80');
		$tester->execute([]);

		// Handler called maintenanceManager->requestShutdown() → run ended with maintenance
		self::assertSame(2, $tester->getStatusCode());
		self::assertStringContainsString('Shutdown requested, stopping jobs gracefully', $tester->getDisplay());
	}

	/**
	 * Sends real SIGTERM to the current process via testCb.
	 * Verifies the handler closure sets shouldStop on the worker.
	 *
	 * @group subprocess
	 */
	public function testWorkerCommandSignalHandlerCallbackStopsLoop(): void
	{
		if (!function_exists('posix_kill')) {
			self::markTestSkipped('posix extension is required');
		}

		$clock = new FrozenClock(1_020);

		$command = new WorkerCommand($clock);

		// testCb fires after subprocess is spawned.
		// Send real SIGTERM to ourselves - handler sets shouldStop=true.
		$command->enableTestMode(1, static function () use ($clock): void {
			$clock->sleep(60);
			posix_kill(getmypid(), SIGTERM);
		});

		$tester = new CommandTester($command);
		putenv('COLUMNS=80');
		$tester->execute([
			'--script' => 'tests/Unit/Command/worker-binary-no-matching-jobs.php',
		], ['interactive' => true]);

		self::assertStringContainsString('Scheduler worker stopped.', $tester->getDisplay());
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	public function testWorkerCommandRequestStopStopsLoop(): void
	{
		$clock = new FrozenClock(1);

		$command = new WorkerCommand($clock);
		$command->enableTestMode(3, static fn () => $clock->sleep(60));

		$command->requestStop();

		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([], ['interactive' => true]);

		$output = $tester->getDisplay();
		self::assertStringContainsString('Scheduler worker stopped.', $output);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	/**
	 * Tests the force-kill path in ProcessJobExecutor.
	 *
	 * @group subprocess
	 *
	 * Flow with FrozenClock:
	 * - Iteration 1: throttle passes (clock 1.0 - lastCheck 0.0 >= 0.1), checker call #2 returns false, jobs start
	 * - Iterations 2-101: throttle blocks (clock advances 1ms/iteration via sleep(0,1))
	 * - Iteration ~101: throttle passes, checker call #3 returns true, shutdown detected
	 * - Same iteration: grace period 0 => elapsed 0.0 >= 0 => force-kill triggers
	 * - Process::stop() kills the sleeping subprocess
	 */
	public function testForceKillOnGracePeriodExpiry(): void
	{
		$clock = new FrozenClock(1);

		$executor = new ProcessJobExecutor($clock);
		$executor->setExecutable(__DIR__ . '/../scheduler-process-binary-sleeping-job.php');

		// Call #1 in runPromise → false, call #2 in first shutdown check → false (jobs start), call #3 → true
		// Grace period 0 configured via MaintenanceManager constructor
		$checker = new DelayedMaintenanceChecker(2);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker, 0);

		$jobManager = new SimpleJobManager();
		$jobManager->addJob(
			new CallbackJob(static function (): void {
				sleep(15);
			}),
			new CronExpression('* * * * *'),
		);

		$scheduler = new ManagedScheduler(
			$jobManager,
			null,
			null,
			$executor,
			$clock,
			null,
			$manager,
			$registry,
		);

		$summary = $scheduler->run();

		self::assertTrue($summary->isMaintenanceActive());
		self::assertCount(1, $summary->getJobSummaries());
		self::assertSame(
			JobResultState::maintenance(),
			$summary->getJobSummaries()[0]->getResult()->getState(),
		);
	}

	/**
	 * Same as above but with a 1-second grace period.
	 * With FrozenClock at 1ms/iteration, ~1000 more iterations after shutdown detection.
	 *
	 * @group subprocess
	 */
	public function testForceKillAfterGracePeriod(): void
	{
		$clock = new FrozenClock(1);

		$executor = new ProcessJobExecutor($clock);
		$executor->setExecutable(__DIR__ . '/../scheduler-process-binary-sleeping-job.php');

		$checker = new DelayedMaintenanceChecker(2);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		// Grace period 1 configured via MaintenanceManager constructor
		$manager = new MaintenanceManager($checker, 1);

		$jobManager = new SimpleJobManager();
		$jobManager->addJob(
			new CallbackJob(static function (): void {
				sleep(15);
			}),
			new CronExpression('* * * * *'),
		);

		$scheduler = new ManagedScheduler(
			$jobManager,
			null,
			null,
			$executor,
			$clock,
			null,
			$manager,
			$registry,
		);

		$summary = $scheduler->run();

		self::assertTrue($summary->isMaintenanceActive());
		self::assertCount(1, $summary->getJobSummaries());
		self::assertSame(
			JobResultState::maintenance(),
			$summary->getJobSummaries()[0]->getResult()->getState(),
		);
	}

	/**
	 * Job finishes naturally within the grace period - no force-kill needed.
	 * The completed job gets its normal state (done), not maintenance.
	 *
	 * @group subprocess
	 */
	public function testGracefulFinishWithinGracePeriod(): void
	{
		$clock = new FrozenClock(1);

		$executor = new ProcessJobExecutor($clock);
		$executor->setExecutable(__DIR__ . '/../scheduler-process-binary-quick-job.php');

		$checker = new DelayedMaintenanceChecker(2);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		// Large grace period so the process has enough real wall time to finish
		// on slower environments (macOS CI). Each FrozenClock second = 1000 loop iterations.
		$manager = new MaintenanceManager($checker, 120);

		$jobManager = new SimpleJobManager();
		$jobManager->addJob(
			new CallbackJob(static function (): void {
				// Quick job - finishes almost immediately
			}),
			new CronExpression('* * * * *'),
			'quick-job',
		);

		$scheduler = new ManagedScheduler(
			$jobManager,
			null,
			null,
			$executor,
			$clock,
			null,
			$manager,
			$registry,
		);

		$summary = $scheduler->run();

		// Maintenance was detected mid-run
		self::assertTrue($summary->isMaintenanceActive());
		// Job finished naturally before force-kill, so it has done state (not maintenance)
		self::assertCount(1, $summary->getJobSummaries());
		self::assertSame(
			JobResultState::done(),
			$summary->getJobSummaries()[0]->getResult()->getState(),
		);
	}

	/**
	 * Tests that force-kill reads valid JSON from a process that completed its job
	 * but is still alive (blocked on sleep).
	 *
	 * @group subprocess
	 *
	 * The binary writes JSON output (RunJobCommand finishes), then blocks on sleep(15).
	 * The process stays "running" so normal check never picks it up.
	 * Force-kill triggers, reads the JSON, and yields a proper done summary.
	 */
	public function testForceKillReadsJsonFromBlockingProcess(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			self::markTestSkipped('Process signal handling differs on Windows');
		}

		$clock = new FrozenClock(1);

		$executor = new ProcessJobExecutor($clock);
		$executor->setExecutable(__DIR__ . '/../scheduler-process-binary-blocking-after-output.php');

		// Delays shutdown detection so subprocess has time to emit its framework events
		// (started + finished) before force-kill. Each throttle check requires 1 FrozenClock
		// second = 1M loop iterations. 50 activates shutdown within ~50M iterations — well
		// before the subprocess's sleep(15) completes on any reasonable CI speed.
		$checker = new DelayedMaintenanceChecker(50);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker, 0);

		$jobManager = new SimpleJobManager();
		$jobManager->addJob(
			new CallbackJob(static function (): void {
				// matches the binary
			}),
			new CronExpression('* * * * *'),
		);

		$scheduler = new ManagedScheduler(
			$jobManager,
			null,
			null,
			$executor,
			$clock,
			null,
			$manager,
			$registry,
		);

		$summary = $scheduler->run();

		self::assertTrue($summary->isMaintenanceActive());
		self::assertCount(1, $summary->getJobSummaries());
		// Process had valid JSON output despite being killed - yields done state, not maintenance
		self::assertSame(
			JobResultState::done(),
			$summary->getJobSummaries()[0]->getResult()->getState(),
		);
	}

	/**
	 * Tests that force-kill logs unexpected stderr from a process that completed its job.
	 *
	 * @group subprocess
	 */
	public function testForceKillLogsStderrFromBlockingProcess(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			self::markTestSkipped('Process signal handling differs on Windows');
		}

		$clock = new FrozenClock(1);
		$logger = new TestLogger();

		$executor = new ProcessJobExecutor($clock, $logger);
		$executor->setExecutable(__DIR__ . '/../scheduler-process-binary-blocking-with-stderr.php');

		$checker = new DelayedMaintenanceChecker(300);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker, 0);

		$jobManager = new SimpleJobManager();
		$jobManager->addJob(
			new CallbackJob(static function (): void {
				fwrite(STDERR, 'job error output');
			}),
			new CronExpression('* * * * *'),
		);

		$scheduler = new ManagedScheduler(
			$jobManager,
			null,
			null,
			$executor,
			$clock,
			$logger,
			$manager,
			$registry,
		);

		$scheduler->run();

		// Verify stderr was logged during force-kill
		$stderrLogs = [];
		foreach ($logger->logs as $log) {
			if (isset($log[2]['stderr'])) {
				$stderrLogs[] = $log;
			}
		}

		self::assertNotEmpty($stderrLogs, 'Expected stderr to be logged during force-kill');
		self::assertStringContainsString('job error output', $stderrLogs[0][2]['stderr']);
	}

	/**
	 * When shutdown is active and a subprocess dies from signal propagation (e.g. SIGINT
	 * to process group), the normal check path should treat it as a maintenance shutdown
	 * instead of throwing RunFailure.
	 *
	 * @group subprocess
	 */
	public function testSignalKilledSubprocessDuringShutdownIsMaintenance(): void
	{
		$clock = new FrozenClock(1);

		$executor = new ProcessJobExecutor($clock);
		// Binary exits immediately with code 130 (simulating SIGINT kill)
		$executor->setExecutable(__DIR__ . '/../scheduler-process-binary-signal-exit.php');

		// Shutdown fires on second throttle check (after subprocess is started)
		$checker = new DelayedMaintenanceChecker(2);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		// Long grace period so force-kill doesn't trigger - the normal check path handles the dead process
		$manager = new MaintenanceManager($checker, 120);

		$jobManager = new SimpleJobManager();
		$jobManager->addJob(
			new CallbackJob(static function (): void {
			}),
			new CronExpression('* * * * *'),
		);

		$scheduler = new ManagedScheduler(
			$jobManager,
			null,
			null,
			$executor,
			$clock,
			null,
			$manager,
			$registry,
		);

		// Should NOT throw RunFailure
		$summary = $scheduler->run();

		self::assertTrue($summary->isMaintenanceActive());
		self::assertCount(1, $summary->getJobSummaries());
		self::assertSame(
			JobResultState::maintenance(),
			$summary->getJobSummaries()[0]->getResult()->getState(),
		);
	}

	/**
	 * Fix A: when ProcessJobExecutor force-kills a subprocess that already emitted `started`,
	 * the parent's beforeJob and afterJob callbacks must share the same executionId. The
	 * parent reuses the JobInfo from the `started` event for the synthesised maintenance
	 * summary rather than creating a new one with parent-clock time.
	 *
	 * @group subprocess
	 */
	public function testForceKillPreservesExecutionIdPairing(): void
	{
		$clock = new FrozenClock(1);

		$executor = new ProcessJobExecutor($clock);
		$executor->setExecutable(__DIR__ . '/../scheduler-process-binary-sleeping-job.php');

		// Delay shutdown detection so the subprocess has real wall time to
		// boot PHP and emit its `started` event before the parent force-kills.
		// 50 throttle checks ≈ 50M iterations — enough for PHP boot on CI but
		// well under the subprocess's sleep(15) fallback exit.
		$checker = new DelayedMaintenanceChecker(50);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker, 0);

		$jobManager = new SimpleJobManager();
		$jobManager->addJob(
			new CallbackJob(static function (): void {
				sleep(15);
			}),
			new CronExpression('* * * * *'),
		);

		$scheduler = new ManagedScheduler(
			$jobManager,
			null,
			null,
			$executor,
			$clock,
			null,
			$manager,
			$registry,
		);

		$beforeIds = [];
		$afterIds = [];
		$afterStates = [];

		$scheduler->addBeforeJobCallback(static function (JobInfo $info) use (&$beforeIds): void {
			$beforeIds[] = $info->getExecutionId();
		});
		$scheduler->addAfterJobCallback(
			static function (JobInfo $info, JobResult $result) use (&$afterIds, &$afterStates): void {
				$afterIds[] = $info->getExecutionId();
				$afterStates[] = $result->getState();
			},
		);

		$scheduler->run();

		self::assertCount(1, $beforeIds);
		self::assertCount(1, $afterIds);
		self::assertSame(
			$beforeIds[0],
			$afterIds[0],
			'beforeJob and afterJob must share executionId even when subprocess was force-killed',
		);
		self::assertSame(JobResultState::maintenance(), $afterStates[0]);
	}

	/**
	 * Fix B: when a subprocess crashes (exits without `finished`) after emitting `started`,
	 * the parent must still fire afterJob for that job. Without the fix, `afterJob` was
	 * silently dropped, breaking the invariant that every beforeJob is paired with afterJob.
	 * RunFailure is still raised to surface the crash.
	 *
	 * @group subprocess
	 */
	public function testSubprocessCrashAfterStartedStillFiresAfterJob(): void
	{
		$clock = new FrozenClock(1);

		$executor = new ProcessJobExecutor($clock);
		$executor->setExecutable(__DIR__ . '/../scheduler-process-binary-crashing-after-started.php');

		$jobManager = new SimpleJobManager();
		$jobManager->addJob(
			new CallbackJob(static function (): void {
				exit(1);
			}),
			new CronExpression('* * * * *'),
		);

		$scheduler = new ManagedScheduler(
			$jobManager,
			null,
			null,
			$executor,
			$clock,
		);

		$beforeIds = [];
		$afterIds = [];
		$afterStates = [];

		$scheduler->addBeforeJobCallback(static function (JobInfo $info) use (&$beforeIds): void {
			$beforeIds[] = $info->getExecutionId();
		});
		$scheduler->addAfterJobCallback(
			static function (JobInfo $info, JobResult $result) use (&$afterIds, &$afterStates): void {
				$afterIds[] = $info->getExecutionId();
				$afterStates[] = $result->getState();
			},
		);

		$caught = null;
		try {
			$scheduler->run();
		} catch (RunFailure $caught) {
			// Expected: subprocess crash surfaces as RunFailure with JobProcessFailure suppressed
		}

		self::assertNotNull($caught);
		self::assertCount(1, $beforeIds);
		self::assertCount(
			1,
			$afterIds,
			'afterJob must still fire when subprocess crashes after emitting `started`',
		);
		self::assertSame($beforeIds[0], $afterIds[0]);
		self::assertSame(JobResultState::fail(), $afterStates[0]);
	}

}
