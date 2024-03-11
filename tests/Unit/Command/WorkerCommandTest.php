<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Command;

use DateTimeZone;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Command\WorkerCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Orisai\Scheduler\Helpers\CommandOutputHelper;
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
		$tester->execute([]);

		self::assertSame(
			<<<'MSG'
Running scheduled tasks every minute.

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	public function testSingleRun(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(1, static fn () => $clock->sleep(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([
			'--script' => 'tests/Unit/Command/worker-binary.php',
		]);

		self::assertSame($command::SUCCESS, $tester->getStatusCode());
		self::assertCount(4, explode(PHP_EOL, $tester->getDisplay()));
	}

	public function testExecutableSetter(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->setExecutable('tests/Unit/Command/worker-binary.php');
		$command->enableTestMode(1, static fn () => $clock->sleep(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([]);

		self::assertSame($command::SUCCESS, $tester->getStatusCode());
		self::assertCount(4, explode(PHP_EOL, $tester->getDisplay()));
	}

	public function testMultipleRuns(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(2, static fn () => $clock->sleep(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([
			'--script' => 'tests/Unit/Command/worker-binary.php',
		]);

		self::assertSame($command::SUCCESS, $tester->getStatusCode());
		self::assertCount(6, explode(PHP_EOL, $tester->getDisplay()));
	}

	public function testNoJobs(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(2, static fn () => $clock->sleep(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([
			'--script' => 'tests/Unit/Command/worker-binary-no-matching-jobs.php',
		]);

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
		$tester->execute([]);

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

}
