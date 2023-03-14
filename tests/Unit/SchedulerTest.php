<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use Exception;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Scheduler;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\RunSummary;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\Scheduler\Doubles\CallbackList;

final class SchedulerTest extends TestCase
{

	public function testBasic(): void
	{
		$scheduler = new Scheduler();

		$i = 0;
		$job = new CallbackJob(
			static function () use (&$i): void {
				$i++;
			},
		);
		$expression = new CronExpression('* * * * *');
		$scheduler->addJob($job, $expression);

		self::assertSame([
			[$job, $expression],
		], $scheduler->getJobs());

		$scheduler->run();
		self::assertSame(1, $i);

		$scheduler->run();
		self::assertSame(2, $i);
	}

	public function testNoJobs(): void
	{
		$scheduler = new Scheduler();

		self::assertSame([], $scheduler->getJobs());

		self::assertEquals(
			new RunSummary([]),
			$scheduler->run(),
		);
	}

	public function testFailingJob(): void
	{
		$scheduler = new Scheduler();
		$cbs = new CallbackList();

		$job1 = new CallbackJob(Closure::fromCallable([$cbs, 'exceptionJob']));
		$scheduler->addJob($job1, new CronExpression('* * * * *'));

		$job2 = new CallbackJob(Closure::fromCallable([$cbs, 'errorJob']));
		$scheduler->addJob($job2, new CronExpression('* * * * *'));

		$i = 0;
		$job3 = new CallbackJob(
			static function () use (&$i): void {
				$i++;
			},
		);
		$scheduler->addJob($job3, new CronExpression('* * * * *'));

		$scheduler->run();
		self::assertSame(1, $i);
	}

	public function testEvents(): void
	{
		$clock = new FrozenClock(1);
		$now = $clock->now();
		$scheduler = new Scheduler($clock);
		$cbs = new CallbackList();

		$job1 = new CallbackJob(Closure::fromCallable([$cbs, 'exceptionJob']));
		$scheduler->addJob($job1, new CronExpression('* * * * *'));

		$job2 = new CallbackJob(Closure::fromCallable([$cbs, 'job1']));
		$scheduler->addJob($job2, new CronExpression('* * * * *'));

		$beforeCollected = [];
		$beforeCb = static function (JobInfo $info) use (&$beforeCollected): void {
			$beforeCollected[] = $info;
		};
		$scheduler->addBeforeJobCallback($beforeCb);

		$afterCollected = [];
		$afterCb = static function (JobInfo $info, JobResult $result) use (&$afterCollected): void {
			$afterCollected[] = [$info, $result];
		};
		$scheduler->addAfterJobCallback($afterCb);

		$scheduler->run();

		self::assertEquals(
			[
				new JobInfo('Tests\Orisai\Scheduler\Doubles\CallbackList::exceptionJob()', '* * * * *', $now),
				new JobInfo('Tests\Orisai\Scheduler\Doubles\CallbackList::job1()', '* * * * *', $now),
			],
			$beforeCollected,
		);
		self::assertEquals(
			[
				[
					new JobInfo('Tests\Orisai\Scheduler\Doubles\CallbackList::exceptionJob()', '* * * * *', $now),
					new JobResult(new CronExpression('* * * * *'), $now, new Exception('test')),
				],
				[
					new JobInfo('Tests\Orisai\Scheduler\Doubles\CallbackList::job1()', '* * * * *', $now),
					new JobResult(new CronExpression('* * * * *'), $now, null),
				],
			],
			$afterCollected,
		);
	}

	public function testTimeMovement(): void
	{
		$clock = new FrozenClock(1);
		$scheduler = new Scheduler($clock);

		$job = new CallbackJob(
			static function (): void {
				// Noop
			},
		);
		$scheduler->addJob($job, new CronExpression('* * * * *'));

		$beforeCollected = [];
		$beforeCb = static function (JobInfo $info) use (&$beforeCollected, $clock): void {
			$beforeCollected[] = $info;
			$clock->move(1);
		};
		$scheduler->addBeforeJobCallback($beforeCb);

		$afterCollected = [];
		$afterCb = static function (JobInfo $info, JobResult $result) use (&$afterCollected): void {
			$afterCollected[] = [$info, $result];
		};
		$scheduler->addAfterJobCallback($afterCb);

		$scheduler->run();

		self::assertEquals(
			[
				new JobInfo(
					'tests/Unit/SchedulerTest.php:135',
					'* * * * *',
					DateTimeImmutable::createFromFormat('U', '1'),
				),
			],
			$beforeCollected,
		);
		self::assertEquals(
			[
				[
					new JobInfo(
						'tests/Unit/SchedulerTest.php:135',
						'* * * * *',
						DateTimeImmutable::createFromFormat('U', '1'),
					),
					new JobResult(
						new CronExpression('* * * * *'),
						DateTimeImmutable::createFromFormat('U', '2'),
						null,
					),
				],
			],
			$afterCollected,
		);
	}

	public function testDueTime(): void
	{
		$clock = new FrozenClock(1);
		$scheduler = new Scheduler($clock);

		$expressions = [];
		$scheduler->addAfterJobCallback(static function (JobInfo $info) use (&$expressions): void {
			$expressions[] = $info->getExpression();
		});

		$job = new CallbackJob(
			static function (): void {
				// Noop
			},
		);
		$scheduler->addJob($job, new CronExpression('* * * * *'));
		$scheduler->addJob($job, new CronExpression('0 * * * *'));
		$scheduler->addJob($job, new CronExpression('1 * * * *'));

		$scheduler->run();
		self::assertSame(
			[
				'* * * * *',
				'0 * * * *',
			],
			$expressions,
		);

		$expressions = [];
		$clock->move(60);
		$scheduler->run();
		self::assertSame(
			[
				'* * * * *',
				'1 * * * *',
			],
			$expressions,
		);

		$expressions = [];
		$clock->move(60);
		$scheduler->run();
		self::assertSame(
			[
				'* * * * *',
			],
			$expressions,
		);
	}

	public function testLongRunningJobDoesNotPreventNextJobToStart(): void
	{
		$clock = new FrozenClock(1);
		$scheduler = new Scheduler($clock);

		$job1 = new CallbackJob(
			static function () use ($clock): void {
				$clock->move(60); // Moves time to next minute, next time will job be not ran
			},
		);
		$scheduler->addJob($job1, new CronExpression('0 * * * *'));

		$i = 0;
		$job2 = new CallbackJob(
			static function () use (&$i): void {
				$i++; // Should be still ran, even if previous job took too much time
			},
		);
		$scheduler->addJob($job2, new CronExpression('0 * * * *'));

		$scheduler->run();
		self::assertSame(1, $i);

		// On second run job is not executed because expression no longer matches
		$scheduler->run();
		self::assertSame(1, $i);
	}

	public function testRunSummary(): void
	{
		$clock = new FrozenClock(1);
		$scheduler = new Scheduler($clock);

		$cbs = new CallbackList();
		$job = new CallbackJob(Closure::fromCallable([$cbs, 'job1']));
		$scheduler->addJob($job, new CronExpression('* * * * *'));
		$scheduler->addJob($job, new CronExpression('* * * * *'));

		$summary = $scheduler->run();

		$now = $clock->now();
		self::assertEquals(
			[
				[
					new JobInfo(
						'Tests\Orisai\Scheduler\Doubles\CallbackList::job1()',
						'* * * * *',
						$now,
					),
					new JobResult(new CronExpression('* * * * *'), $now, null),
				],
				[
					new JobInfo(
						'Tests\Orisai\Scheduler\Doubles\CallbackList::job1()',
						'* * * * *',
						$now,
					),
					new JobResult(new CronExpression('* * * * *'), $now, null),
				],
			],
			$summary->getJobs(),
		);
	}

}
