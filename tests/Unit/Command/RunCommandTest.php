<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Command;

use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Command\RunCommand;
use Orisai\Scheduler\Executor\ProcessJobExecutor;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Maintenance\MaintenanceManager;
use Orisai\Scheduler\RunRegistry\FileRunRegistry;
use Orisai\Scheduler\SimpleScheduler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\Store\InMemoryStore;
use Tests\Orisai\Scheduler\Doubles\CallbackList;
use Tests\Orisai\Scheduler\Doubles\CustomNameJob;
use Tests\Orisai\Scheduler\Doubles\FileExistsMaintenanceChecker;
use Tests\Orisai\Scheduler\Doubles\TestLockFactory;
use Tests\Orisai\Scheduler\Helpers\CommandOutputHelper;
use Tests\Orisai\Scheduler\Unit\SchedulerProcessSetup;
use function explode;
use function file_put_contents;
use function putenv;
use function sort;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
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

		$tester->execute([]);

		self::assertSame(
			<<<'MSG'

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
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
			null,
			0,
			new DateTimeZone('UTC'),
		);

		$command = new RunCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');

		$tester->execute([]);
		self::assertSame(
			<<<'MSG'
1970-01-01 01:00:01 Running [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() 0ms DONE
1970-01-01 01:00:01 Running [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job2() 0ms DONE

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());

		putenv('COLUMNS=100');

		$clock->sleep(60);
		$tester->execute([]);
		self::assertSame(
			<<<'MSG'
1970-01-01 01:01:01 Running [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()........ 0ms DONE
1970-01-01 01:01:01 Running [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job2()........ 0ms DONE

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());

		$clock->sleep(60);
		$tester->execute([
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
            "repeatAfterSeconds": 0,
            "runSecond": 0,
            "start": [
                "121.000000",
                "Europe\/Prague"
            ],
            "forcedRun": false
        },
        "result": {
            "end": [
                "121.000000",
                "Europe\/Prague"
            ],
            "state": "done"
        }
    },
    {
        "info": {
            "id": 1,
            "name": "Tests\\Orisai\\Scheduler\\Doubles\\CallbackList::job2()",
            "expression": "* * * * *",
            "repeatAfterSeconds": 0,
            "runSecond": 0,
            "start": [
                "121.000000",
                "UTC"
            ],
            "forcedRun": false
        },
        "result": {
            "end": [
                "121.000000",
                "UTC"
            ],
            "state": "done"
        }
    }
]

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
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

		$command = new RunCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');

		$tester->execute([]);
		self::assertSame(
			<<<'MSG'
1970-01-01 01:00:01 Running [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() 0ms DONE
1970-01-01 01:00:01 Running [1] Tests\Orisai\Scheduler\Doubles\CallbackList::exceptionJob() 0ms FAIL

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::FAILURE, $tester->getStatusCode());

		$clock->sleep(60);
		$tester->execute([
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
            "repeatAfterSeconds": 0,
            "runSecond": 0,
            "start": [
                "61.000000",
                "Europe\/Prague"
            ],
            "forcedRun": false
        },
        "result": {
            "end": [
                "61.000000",
                "Europe\/Prague"
            ],
            "state": "done"
        }
    },
    {
        "info": {
            "id": 1,
            "name": "Tests\\Orisai\\Scheduler\\Doubles\\CallbackList::exceptionJob()",
            "expression": "* * * * *",
            "repeatAfterSeconds": 0,
            "runSecond": 0,
            "start": [
                "61.000000",
                "Europe\/Prague"
            ],
            "forcedRun": false
        },
        "result": {
            "end": [
                "61.000000",
                "Europe\/Prague"
            ],
            "state": "fail"
        }
    }
]

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::FAILURE, $tester->getStatusCode());
	}

	public function testLock(): void
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

		$lock = $lockFactory->createLock('Orisai.Scheduler.Job/0');
		$lock->acquire();

		$command = new RunCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');

		$tester->execute([]);
		self::assertSame(
			<<<'MSG'
1970-01-01 01:00:01 Running [0] job1................................... 0ms LOCK

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	public function testMaintenanceExitCode(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($maintenanceFile, '');

		$checker = new FileExistsMaintenanceChecker($maintenanceFile);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
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

		unlink($maintenanceFile);
	}

	public function testMaintenanceExitCodeJson(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($maintenanceFile, '');

		$checker = new FileExistsMaintenanceChecker($maintenanceFile);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$executor = new ProcessJobExecutor($clock);
		$scheduler = new SimpleScheduler(null, null, $executor, $clock, null, $manager, $registry);

		$scheduler->addJob(
			new CallbackJob(static function (): void {
			}),
			new CronExpression('* * * * *'),
		);

		$command = new RunCommand($scheduler, $clock, $manager);
		$tester = new CommandTester($command);

		$tester->execute(['--json' => true]);

		self::assertSame(2, $tester->getStatusCode());
		self::assertStringContainsString('"state": "maintenance"', $tester->getDisplay());

		unlink($maintenanceFile);
	}

	/**
	 * @group subprocess
	 */
	public function testProcessExecutor(): void
	{
		$scheduler = SchedulerProcessSetup::createWithErrorHandler();

		$command = new RunCommand($scheduler);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');

		$tester->execute([]);
		$displayLines = explode(PHP_EOL, $tester->getDisplay());
		sort($displayLines);

		self::assertEquals(
			[
				'',
				'1970-01-01 00:00:01 Running [0] Tests\Orisai\Scheduler\Doubles\CallbackList::exceptionJob() 0ms FAIL',
				'1970-01-01 00:00:01 Running [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() 0ms DONE',
				'1970-01-01 00:00:01 Running [job1] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() 0ms DONE',
				'1970-01-01 00:00:01 Running [job1] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() 0ms DONE',
			],
			$displayLines,
		);
		self::assertSame($command::FAILURE, $tester->getStatusCode());
	}

}
