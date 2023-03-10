<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Command;

use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Command\RunCommand;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\SimpleScheduler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Orisai\Scheduler\Doubles\CallbackList;
use function array_map;
use function explode;
use function implode;
use function putenv;
use function rtrim;
use const PHP_EOL;

/**
 * @runTestsInSeparateProcesses
 */
final class RunCommandTest extends TestCase
{

	public function testNoJobs(): void
	{
		$scheduler = new SimpleScheduler();

		$command = new RunCommand($scheduler);
		$tester = new CommandTester($command);

		$code = $tester->execute([]);

		self::assertSame(
			<<<'MSG'

MSG,
			$tester->getDisplay(),
		);
		self::assertSame($command::SUCCESS, $code);
	}

	public function testSuccess(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler($clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('* * * * *'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job2'])),
			new CronExpression('* * * * *'),
		);

		$command = new RunCommand($scheduler);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([]);

		self::assertSame(
			<<<'MSG'
1970-01-01 01:00:01 Running Tests\Orisai\Scheduler\Doubles\CallbackList::job1() 0ms DONE
1970-01-01 01:00:01 Running Tests\Orisai\Scheduler\Doubles\CallbackList::job2() 0ms DONE

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

		putenv('COLUMNS=100');
		$code = $tester->execute([]);

		self::assertSame(
			<<<'MSG'
1970-01-01 01:00:01 Running Tests\Orisai\Scheduler\Doubles\CallbackList::job1()............ 0ms DONE
1970-01-01 01:00:01 Running Tests\Orisai\Scheduler\Doubles\CallbackList::job2()............ 0ms DONE

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

	public function testFailure(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler($clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('* * * * *'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'exceptionJob'])),
			new CronExpression('* * * * *'),
		);

		$command = new RunCommand($scheduler);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([]);

		self::assertSame(
			<<<'MSG'
1970-01-01 01:00:01 Running Tests\Orisai\Scheduler\Doubles\CallbackList::job1() 0ms DONE
1970-01-01 01:00:01 Running Tests\Orisai\Scheduler\Doubles\CallbackList::exceptionJob() 0ms FAIL

MSG,
			implode(
				PHP_EOL,
				array_map(
					static fn (string $s): string => rtrim($s),
					explode(PHP_EOL, $tester->getDisplay()),
				),
			),
		);
		self::assertSame($command::FAILURE, $code);
	}

}
