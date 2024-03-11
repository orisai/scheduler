<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Command;

use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Orisai\Clock\FrozenClock;
use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Scheduler\Command\RunJobCommand;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\SimpleScheduler;
use Orisai\Scheduler\Status\RunParameters;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\Store\InMemoryStore;
use Tests\Orisai\Scheduler\Doubles\CallbackList;
use Tests\Orisai\Scheduler\Doubles\CustomNameJob;
use Tests\Orisai\Scheduler\Doubles\TestLockFactory;
use Tests\Orisai\Scheduler\Helpers\CommandOutputHelper;
use function json_encode;
use function putenv;
use const JSON_THROW_ON_ERROR;

/**
 * @runTestsInSeparateProcesses
 */
final class RunJobCommandTest extends TestCase
{

	public function testNonExistentJob(): void
	{
		$scheduler = new SimpleScheduler();

		$command = new RunJobCommand($scheduler);
		$tester = new CommandTester($command);

		$this->expectException(InvalidArgument::class);
		$this->expectExceptionMessage(
			<<<'MSG'
Context: Running job with ID 'id'
Problem: Job is not registered by scheduler.
Tip: Inspect keys in 'Scheduler->getJobSchedules()' or run command
     'scheduler:list' to find correct job ID.
MSG,
		);

		$tester->execute([
			'id' => 'id',
		]);
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

		$command = new RunJobCommand($scheduler);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([
			'id' => 0,
		]);

		self::assertSame(
			<<<'MSG'
1970-01-01 01:00:01 Running [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() 0ms DONE

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());

		putenv('COLUMNS=100');
		$tester->execute([
			'id' => 0,
		]);

		self::assertSame(
			<<<'MSG'
1970-01-01 01:00:01 Running [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()........ 0ms DONE

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
			new CallbackJob(Closure::fromCallable([$cbs, 'exceptionJob'])),
			new CronExpression('* * * * *'),
		);

		$command = new RunJobCommand($scheduler);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([
			'id' => 0,
		]);

		self::assertSame(
			<<<'MSG'
1970-01-01 01:00:01 Running [0] Tests\Orisai\Scheduler\Doubles\CallbackList::exceptionJob() 0ms FAIL

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

		$command = new RunJobCommand($scheduler);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([
			'id' => 0,
		]);

		self::assertSame(
			<<<'MSG'
1970-01-01 01:00:01 Running [0] job1................................... 0ms LOCK

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	public function testNoForce(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('0 * * * *'),
		);

		$command = new RunJobCommand($scheduler);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([
			'id' => 0,
			'--no-force' => true,
		]);

		self::assertSame(
			<<<'MSG'
1970-01-01 01:00:01 Running [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() 0ms DONE

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());

		$clock->sleep(60);
		$tester->execute([
			'id' => 0,
			'--no-force' => true,
		]);

		self::assertSame(
			<<<'MSG'
Command was not executed because it is not its due time

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	public function testJson(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('1 * * * *'),
		);

		$command = new RunJobCommand($scheduler);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([
			'id' => 0,
			'--no-force' => true,
			'--json' => true,
		]);

		self::assertSame(
			<<<'MSG'
null

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());

		$clock->sleep(60);
		$tester->execute([
			'id' => 0,
			'--json' => true,
			'--parameters' => json_encode((new RunParameters(30))->toArray(), JSON_THROW_ON_ERROR),
		]);

		self::assertSame(
			<<<'MSG'
{
    "info": {
        "id": 0,
        "name": "Tests\\Orisai\\Scheduler\\Doubles\\CallbackList::job1()",
        "expression": "1 * * * *",
        "repeatAfterSeconds": 0,
        "runSecond": 30,
        "start": "61.000000"
    },
    "result": {
        "end": "61.000000",
        "state": "done"
    },
    "stdout": ""
}

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	public function testEchoingJob(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'echoingJob'])),
			new CronExpression('* * * * *'),
		);

		$command = new RunJobCommand($scheduler);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');

		$tester->execute([
			'id' => 0,
		]);

		self::assertSame(
			<<<'MSG'
output
1970-01-01 01:00:01 Running [0] Tests\Orisai\Scheduler\Doubles\CallbackList::echoingJob() 0ms DONE

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());

		$tester->execute([
			'id' => 0,
			'--json' => true,
		]);

		self::assertSame(
			<<<'MSG'
{
    "info": {
        "id": 0,
        "name": "Tests\\Orisai\\Scheduler\\Doubles\\CallbackList::echoingJob()",
        "expression": "* * * * *",
        "repeatAfterSeconds": 0,
        "runSecond": 0,
        "start": "1.000000"
    },
    "result": {
        "end": "1.000000",
        "state": "done"
    },
    "stdout": "output"
}

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

}
