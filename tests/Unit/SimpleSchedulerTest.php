<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Exception\JobsExecutionFailure;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\SimpleScheduler;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\RunSummary;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Store\InMemoryStore;
use Tests\Orisai\Scheduler\Doubles\CallbackList;
use Tests\Orisai\Scheduler\Doubles\CustomNameJob;
use Tests\Orisai\Scheduler\Doubles\JobFailure;
use Tests\Orisai\Scheduler\Doubles\TestLockFactory;
use Throwable;

final class SimpleSchedulerTest extends TestCase
{

	public function testBasic(): void
	{
		$scheduler = new SimpleScheduler();

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
		$scheduler = new SimpleScheduler();

		self::assertSame([], $scheduler->getJobs());

		self::assertEquals(
			new RunSummary([]),
			$scheduler->run(),
		);
	}

	public function testFailingJob(): void
	{
		$scheduler = new SimpleScheduler();
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

		$e = null;
		try {
			$scheduler->run();
		} catch (JobsExecutionFailure $e) {
			// Handled bellow
		}

		self::assertSame(1, $i);
		self::assertInstanceOf(JobsExecutionFailure::class, $e);
		self::assertCount(2, $e->getSuppressed());
	}

	public function testFailingJobWithoutThrow(): void
	{
		$errors = [];
		$errorHandler = static function (Throwable $throwable) use (&$errors): void {
			$errors[] = $throwable;
		};
		$scheduler = new SimpleScheduler($errorHandler);
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
		self::assertCount(2, $errors);
	}

	public function testEvents(): void
	{
		$errorHandler = static function (): void {
			// Noop
		};
		$clock = new FrozenClock(1);
		$now = $clock->now();
		$scheduler = new SimpleScheduler($errorHandler, null, $clock);
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
					new JobResult(new CronExpression('* * * * *'), $now, JobResultState::fail()),
				],
				[
					new JobInfo('Tests\Orisai\Scheduler\Doubles\CallbackList::job1()', '* * * * *', $now),
					new JobResult(new CronExpression('* * * * *'), $now, JobResultState::done()),
				],
			],
			$afterCollected,
		);
	}

	public function testTimeMovement(): void
	{
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, null, $clock);

		$jobLine = __LINE__ + 2;
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
					"tests/Unit/SimpleSchedulerTest.php:$jobLine",
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
						"tests/Unit/SimpleSchedulerTest.php:$jobLine",
						'* * * * *',
						DateTimeImmutable::createFromFormat('U', '1'),
					),
					new JobResult(
						new CronExpression('* * * * *'),
						DateTimeImmutable::createFromFormat('U', '2'),
						JobResultState::done(),
					),
				],
			],
			$afterCollected,
		);
	}

	public function testDueTime(): void
	{
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, null, $clock);

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
		$scheduler = new SimpleScheduler(null, null, $clock);

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
		$scheduler = new SimpleScheduler(null, null, $clock);

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
					new JobResult(new CronExpression('* * * * *'), $now, JobResultState::done()),
				],
				[
					new JobInfo(
						'Tests\Orisai\Scheduler\Doubles\CallbackList::job1()',
						'* * * * *',
						$now,
					),
					new JobResult(new CronExpression('* * * * *'), $now, JobResultState::done()),
				],
			],
			$summary->getJobs(),
		);
	}

	public function testLockAlreadyAcquired(): void
	{
		$lockFactory = new TestLockFactory(new InMemoryStore(), false);
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, $lockFactory, $clock);

		$i1 = 0;
		$job1 = new CallbackJob(
			static function () use (&$i1): void {
				$i1++;
			},
		);
		$scheduler->addJob(
			new CustomNameJob($job1, 'job1'),
			new CronExpression('* * * * *'),
		);

		$i2 = 0;
		$job2 = new CallbackJob(
			static function () use (&$i2): void {
				$i2++;
			},
		);
		$scheduler->addJob(
			new CustomNameJob($job2, 'job2'),
			new CronExpression('* * * * *'),
		);

		$lock = $lockFactory->createLock('Orisai.Scheduler.Job/* * * * *-job1-0');
		$lock->acquire();

		// Lock is active, job is not executed (but the other one is)
		$result = $scheduler->run();
		self::assertSame(0, $i1);
		self::assertSame(1, $i2);
		self::assertEquals(
			[
				[
					new JobInfo('job1', '* * * * *', $clock->now()),
					new JobResult(new CronExpression('* * * * *'), $clock->now(), JobResultState::skip()),
				],
				[
					new JobInfo('job2', '* * * * *', $clock->now()),
					new JobResult(new CronExpression('* * * * *'), $clock->now(), JobResultState::done()),
				],
			],
			$result->getJobs(),
		);
		self::assertSame(
			$result->getJobs()[0][0]->getStart(),
			$result->getJobs()[0][1]->getEnd(),
		);
		self::assertNotSame(
			$result->getJobs()[1][0]->getStart(),
			$result->getJobs()[1][1]->getEnd(),
		);

		$lock->release();

		// Lock was released, job is executed
		$result = $scheduler->run();
		self::assertSame(1, $i1);
		self::assertSame(2, $i2);
		self::assertEquals(
			[
				[
					new JobInfo('job1', '* * * * *', $clock->now()),
					new JobResult(new CronExpression('* * * * *'), $clock->now(), JobResultState::done()),
				],
				[
					new JobInfo('job2', '* * * * *', $clock->now()),
					new JobResult(new CronExpression('* * * * *'), $clock->now(), JobResultState::done()),
				],
			],
			$result->getJobs(),
		);

		$scheduler->run();
		self::assertSame(2, $i1);
		self::assertSame(3, $i2);
	}

	public function testLockIsReleasedAfterAnExceptionInJob(): void
	{
		$errorHandler = static function (): void {
			// Noop
		};
		$lockFactory = new TestLockFactory(new InMemoryStore(), false);
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler($errorHandler, $lockFactory, $clock);

		$throw = true;
		$i = 0;
		$job = new CallbackJob(
			static function () use (&$i, &$throw): void {
				$i++;
				if ($throw) {
					throw new JobFailure('');
				}

				$i++;
			},
		);
		$scheduler->addJob($job, new CronExpression('* * * * *'));

		$scheduler->run();
		self::assertSame(1, $i);

		// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
		$throw = false;
		$scheduler->run();
		self::assertSame(3, $i);
	}

	public function testLockIsReleasedAfterAnExceptionInBeforeCallback(): void
	{
		$lockFactory = new TestLockFactory(new InMemoryStore(), false);
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, $lockFactory, $clock);

		$i = 0;
		$job = new CallbackJob(
			static function () use (&$i): void {
				$i++;
			},
		);
		$scheduler->addJob($job, new CronExpression('* * * * *'));

		$throw = true;
		$scheduler->addBeforeJobCallback(static function () use (&$throw): void {
			if ($throw) {
				throw new JobFailure('');
			}
		});

		$e = null;
		try {
			$scheduler->run();
		} catch (JobFailure $e) {
			// Handled bellow
		}

		self::assertInstanceOf(JobFailure::class, $e);
		self::assertSame(0, $i);

		// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
		$throw = false;
		$scheduler->run();
		self::assertSame(1, $i);

		$scheduler->run();
		self::assertSame(2, $i);
	}

	public function testLockIsReleasedAfterAnExceptionInAfterCallback(): void
	{
		$lockFactory = new TestLockFactory(new InMemoryStore(), false);
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, $lockFactory, $clock);

		$i = 0;
		$job = new CallbackJob(
			static function () use (&$i): void {
				$i++;
			},
		);
		$scheduler->addJob($job, new CronExpression('* * * * *'));

		$throw = true;
		$scheduler->addAfterJobCallback(static function () use (&$throw): void {
			if ($throw) {
				throw new JobFailure('');
			}
		});

		$e = null;
		try {
			$scheduler->run();
		} catch (JobFailure $e) {
			// Handled bellow
		}

		self::assertInstanceOf(JobFailure::class, $e);
		self::assertSame(1, $i);

		// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
		$throw = false;
		$scheduler->run();
		self::assertSame(2, $i);

		$scheduler->run();
		self::assertSame(3, $i);
	}

}
