<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Command;

use DateTimeZone;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Command\WorkerCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use function array_map;
use function explode;
use function implode;
use function preg_replace;
use function putenv;
use function rtrim;
use const PHP_EOL;

final class WorkerCommandTest extends TestCase
{

	public function testNoRuns(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(0, static fn () => $clock->move(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([]);

		self::assertSame(
			<<<'MSG'
Running scheduled tasks every minute.

MSG,
			implode(
				PHP_EOL,
				array_map(
					static fn (string $s): string => rtrim($s),
					explode(PHP_EOL, $tester->getDisplay()),
				),
			),
		);
		self::assertSame($command::SUCCESS, $code);
	}

	public function testSingleRun(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(1, static fn () => $clock->move(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([
			'--script' => 'tests/Unit/Command/worker-binary.php',
		]);

		self::assertSame($command::SUCCESS, $code);
		self::assertCount(4, explode(PHP_EOL, $tester->getDisplay()));
	}

	public function testExecutableSetter(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->setExecutable('tests/Unit/Command/worker-binary.php');
		$command->enableTestMode(1, static fn () => $clock->move(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([]);

		self::assertSame($command::SUCCESS, $code);
		self::assertCount(4, explode(PHP_EOL, $tester->getDisplay()));
	}

	public function testMultipleRuns(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(2, static fn () => $clock->move(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([
			'--script' => 'tests/Unit/Command/worker-binary.php',
		]);

		self::assertSame($command::SUCCESS, $code);
		self::assertCount(6, explode(PHP_EOL, $tester->getDisplay()));
	}

	public function testNoJobs(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(2, static fn () => $clock->move(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([
			'--script' => 'tests/Unit/Command/worker-binary-no-matching-jobs.php',
		]);

		self::assertSame($command::SUCCESS, $code);
		self::assertSame(
			<<<'MSG'
Running scheduled tasks every minute.

MSG,
			implode(
				PHP_EOL,
				array_map(
					static fn (string $s): string => rtrim($s),
					explode(PHP_EOL, $tester->getDisplay()),
				),
			),
		);
	}

	public function testDefaultExecutable(): void
	{
		$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));

		$command = new WorkerCommand($clock);
		$command->enableTestMode(2, static fn () => $clock->move(60));
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([]);

		self::assertSame(
			<<<'MSG'
Running scheduled tasks every minute.
Could not open input file: bin/console
Could not open input file: bin/console

MSG,
			implode(
				PHP_EOL,
				array_map(
					static fn (string $s): string => rtrim($s),
					explode(PHP_EOL, preg_replace('~\R~u', PHP_EOL, $tester->getDisplay())),
				),
			),
		);
		self::assertSame($command::SUCCESS, $code);
	}

}
