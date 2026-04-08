<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Command;

use DateTimeZone;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Command\WorkerCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Orisai\Scheduler\Helpers\CommandOutputHelper;
use function count;
use function explode;
use function putenv;
use const PHP_EOL;

final class WorkerCommandTest extends TestCase
{

	public function testNoRuns(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(0, static fn () => $clock->sleep(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([], ['interactive' => true]);

		self::assertSame(
			<<<'MSG'
Running scheduled tasks every minute.

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	/**
	 * @group subprocess
	 */
	public function testSingleRun(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(1, static fn () => $clock->sleep(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([
			'--script' => 'tests/Unit/Command/worker-binary.php',
		], ['interactive' => true]);

		self::assertSame($command::SUCCESS, $tester->getStatusCode());
		self::assertCount(4, explode(PHP_EOL, $tester->getDisplay()));
	}

	/**
	 * @group subprocess
	 */
	public function testExecutableSetter(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->setExecutable('tests/Unit/Command/worker-binary.php');
		$command->enableTestMode(1, static fn () => $clock->sleep(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([], ['interactive' => true]);

		self::assertSame($command::SUCCESS, $tester->getStatusCode());
		self::assertCount(4, explode(PHP_EOL, $tester->getDisplay()));
	}

	/**
	 * @group subprocess
	 */
	public function testMultipleRuns(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(2, static fn () => $clock->sleep(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([
			'--script' => 'tests/Unit/Command/worker-binary.php',
		], ['interactive' => true]);

		self::assertSame($command::SUCCESS, $tester->getStatusCode());
		self::assertCount(6, explode(PHP_EOL, $tester->getDisplay()));
	}

	/**
	 * @group subprocess
	 */
	public function testNoJobs(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(2, static fn () => $clock->sleep(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([
			'--script' => 'tests/Unit/Command/worker-binary-no-matching-jobs.php',
		], ['interactive' => true]);

		self::assertSame($command::SUCCESS, $tester->getStatusCode());
		self::assertSame(
			<<<'MSG'
Running scheduled tasks every minute.

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
	}

	public function testDefaultExecutable(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(2, static fn () => $clock->sleep(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([], ['interactive' => true]);

		self::assertSame(
			<<<'MSG'
Running scheduled tasks every minute.
Could not open input file: bin/console
Could not open input file: bin/console

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	public function testNonInteractive(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(0, static fn () => $clock->sleep(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([], ['interactive' => false]);

		self::assertSame(
			<<<'MSG'
CLI is non-interactive. If you are sure you can terminate the worker and want to bypass limitation, then use the --force option.

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::FAILURE, $tester->getStatusCode());
	}

	/**
	 * @group subprocess
	 */
	public function testSignalStopWaitsForSubprocesses(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		// Spawn 1 subprocess, then in the callback signal stop.
		// The subprocess is still in $executions when the main loop breaks,
		// so the "wait for subprocesses" loop executes.
		$command->enableTestMode(1, static function () use ($command, $clock): void {
			$clock->sleep(60);
			$command->requestStop();
		});
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([
			'--script' => 'tests/Unit/Command/worker-binary.php',
		], ['interactive' => true]);

		$output = CommandOutputHelper::getCommandOutput($tester);
		// Subprocess output was captured (wait loop processed it)
		self::assertStringContainsString('Running scheduled tasks every minute.', $output);
		self::assertStringContainsString('Scheduler worker stopped.', $output);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
		// Verify subprocess actually ran by checking for job output lines
		self::assertGreaterThan(
			2,
			count(explode(PHP_EOL, $tester->getDisplay())),
		);
	}

	public function testNonInteractiveForce(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(0, static fn () => $clock->sleep(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([
			'--force' => true,
		], ['interactive' => false]);

		self::assertSame(
			<<<'MSG'
Running scheduled tasks every minute.

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

}
