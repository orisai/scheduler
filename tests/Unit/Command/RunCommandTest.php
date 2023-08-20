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
use Symfony\Component\Lock\Store\InMemoryStore;
use Tests\Orisai\Scheduler\Doubles\CallbackList;
use Tests\Orisai\Scheduler\Doubles\CustomNameJob;
use Tests\Orisai\Scheduler\Doubles\TestLockFactory;
use Tests\Orisai\Scheduler\Unit\SchedulerProcessSetup;
use function array_map;
use function explode;
use function implode;
use function preg_replace;
use function putenv;
use function rtrim;
use function sort;
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
		$scheduler = new SimpleScheduler(null, null, null, $clock);

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
1970-01-01 01:00:01 Running [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() 0ms DONE
1970-01-01 01:00:01 Running [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job2() 0ms DONE

MSG,
			$this->getNormalizedLines($tester),
		);
		self::assertSame($command::SUCCESS, $code);

		putenv('COLUMNS=100');

		$code = $tester->execute([]);
		self::assertSame(
			<<<'MSG'
1970-01-01 01:00:01 Running [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()........ 0ms DONE
1970-01-01 01:00:01 Running [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job2()........ 0ms DONE

MSG,
			$this->getNormalizedLines($tester),
		);
		self::assertSame($command::SUCCESS, $code);

		$code = $tester->execute([
			'--json' => true,
		]);
		self::assertSame(
			<<<'MSG'
[
    {
        "info": {
            "id": 0,
            "name": "Tests\\Orisai\\Scheduler\\Doubles\\CallbackList::job1()",
            "expression": "* * * * *",
            "start": "1.000"
        },
        "result": {
            "end": "1.000",
            "state": "done"
        }
    },
    {
        "info": {
            "id": 1,
            "name": "Tests\\Orisai\\Scheduler\\Doubles\\CallbackList::job2()",
            "expression": "* * * * *",
            "start": "1.000"
        },
        "result": {
            "end": "1.000",
            "state": "done"
        }
    }
]

MSG,
			$this->getNormalizedLines($tester),
		);
		self::assertSame($command::SUCCESS, $code);
	}

	public function testFailure(): void
	{
		$errorHandler = static function (): void {
			// Noop
		};
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler($errorHandler, null, null, $clock);

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
1970-01-01 01:00:01 Running [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() 0ms DONE
1970-01-01 01:00:01 Running [1] Tests\Orisai\Scheduler\Doubles\CallbackList::exceptionJob() 0ms FAIL

MSG,
			$this->getNormalizedLines($tester),
		);
		self::assertSame($command::FAILURE, $code);

		$code = $tester->execute([
			'--json' => true,
		]);
		self::assertSame(
			<<<'MSG'
[
    {
        "info": {
            "id": 0,
            "name": "Tests\\Orisai\\Scheduler\\Doubles\\CallbackList::job1()",
            "expression": "* * * * *",
            "start": "1.000"
        },
        "result": {
            "end": "1.000",
            "state": "done"
        }
    },
    {
        "info": {
            "id": 1,
            "name": "Tests\\Orisai\\Scheduler\\Doubles\\CallbackList::exceptionJob()",
            "expression": "* * * * *",
            "start": "1.000"
        },
        "result": {
            "end": "1.000",
            "state": "fail"
        }
    }
]

MSG,
			$this->getNormalizedLines($tester),
		);
		self::assertSame($command::FAILURE, $code);
	}

	public function testSkip(): void
	{
		$lockFactory = new TestLockFactory(new InMemoryStore(), false);
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, $lockFactory, null, $clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CustomNameJob(
				new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
				'job1',
			),
			new CronExpression('* * * * *'),
		);

		$lock = $lockFactory->createLock('Orisai.Scheduler.Job/* * * * *-job1-0');
		$lock->acquire();

		$command = new RunCommand($scheduler);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([]);

		self::assertSame(
			<<<'MSG'
1970-01-01 01:00:01 Running [0] job1................................... 0ms SKIP

MSG,
			$this->getNormalizedLines($tester),
		);
		self::assertSame($command::SUCCESS, $code);
	}

	public function testProcessExecutor(): void
	{
		$scheduler = SchedulerProcessSetup::createWithErrorHandler();

		$command = new RunCommand($scheduler);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([]);

		$displayLines = explode(PHP_EOL, $tester->getDisplay());
		sort($displayLines);

		self::assertEquals(
			[
				'',
				'1970-01-01 00:00:01 Running [0] Tests\Orisai\Scheduler\Doubles\CallbackList::exceptionJob() 0ms FAIL',
				'1970-01-01 00:00:01 Running [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() 0ms DONE',
				'1970-01-01 00:00:01 Running [job1] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() 0ms DONE',
			],
			$displayLines,
		);
		self::assertSame($command::FAILURE, $code);
	}

	public function getNormalizedLines(CommandTester $tester): string
	{
		return implode(
			PHP_EOL,
			array_map(
				static fn (string $s): string => rtrim($s),
				explode(PHP_EOL, preg_replace('~\R~u', PHP_EOL, $tester->getDisplay())),
			),
		);
	}

}
