<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use Orisai\Clock\FrozenClock;
use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Scheduler\Exception\JobFailure;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\SimpleScheduler;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunSummary;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Store\InMemoryStore;
use Tests\Orisai\Scheduler\Doubles\CallbackList;
use Tests\Orisai\Scheduler\Doubles\CustomNameJob;
use Tests\Orisai\Scheduler\Doubles\JobInnerFailure;
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

		$scheduler->runJob(0);
		self::assertSame(3, $i);

		$scheduler->runJob(0, false);
		self::assertSame(4, $i);
	}

	public function testJobKey(): void
	{
		$scheduler = new SimpleScheduler();

		$i = 0;
		$job = new CallbackJob(
			static function () use (&$i): void {
				$i++;
			},
		);
		$expression = new CronExpression('* * * * *');
		$key = 'key';
		$scheduler->addJob($job, $expression, $key);

		self::assertSame([
			$key => [$job, $expression],
		], $scheduler->getJobs());

		$scheduler->runJob($key);
		self::assertSame(1, $i);
	}

	public function testNoJobs(): void
	{
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		self::assertSame([], $scheduler->getJobs());

		self::assertEquals(
			new RunSummary($clock->now(), $clock->now(), []),
			$scheduler->run(),
		);

		$this->expectException(InvalidArgument::class);
		$this->expectExceptionMessage(
			<<<'MSG'
Context: Running job with ID '0'
Problem: Job is not registered by scheduler.
Tip: Inspect keys in 'Scheduler->getJobs()' or run command 'scheduler:list' to
     find correct job ID.
MSG,
		);
		$scheduler->runJob(0);
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
		} catch (RunFailure $e) {
			// Handled bellow
		}

		self::assertSame(1, $i);
		self::assertInstanceOf(RunFailure::class, $e);
		self::assertCount(2, $e->getSuppressed());

		$e = null;
		try {
			$scheduler->runJob(0);
		} catch (JobFailure $e) {
			// Handled bellow
		}

		self::assertInstanceOf(JobFailure::class, $e);
		self::assertInstanceOf(Throwable::class, $e->getPrevious());
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

		$scheduler->runJob(0);
		self::assertCount(3, $errors);
	}

	public function testEvents(): void
	{
		$errorHandler = static function (): void {
			// Noop
		};
		$clock = new FrozenClock(1);
		$now = $clock->now();
		$scheduler = new SimpleScheduler($errorHandler, null, null, $clock);
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
		self::assertCount(2, $beforeCollected);
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
		self::assertCount(2, $afterCollected);

		$scheduler->runJob(0);
		self::assertCount(3, $beforeCollected);
		self::assertCount(3, $afterCollected);
	}

	public function testTimeMovement(): void
	{
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, null, null, $clock);

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
		$scheduler = new SimpleScheduler(null, null, null, $clock);

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
		self::assertNotNull($scheduler->runJob(0, false));
		self::assertNotNull($scheduler->runJob(1, false));
		self::assertNull($scheduler->runJob(2, false));

		self::assertNotNull($scheduler->runJob(0));
		self::assertNotNull($scheduler->runJob(1));
		self::assertNotNull($scheduler->runJob(2));

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
		self::assertNotNull($scheduler->runJob(0, false));
		self::assertNull($scheduler->runJob(1, false));
		self::assertNotNull($scheduler->runJob(2, false));

		$expressions = [];
		$clock->move(60);
		$scheduler->run();
		self::assertSame(
			[
				'* * * * *',
			],
			$expressions,
		);
		self::assertNotNull($scheduler->runJob(0, false));
		self::assertNull($scheduler->runJob(1, false));
		self::assertNull($scheduler->runJob(2, false));
	}

	public function testLongRunningJobDoesNotPreventNextJobToStart(): void
	{
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, null, null, $clock);

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
		$before = $clock->now();
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('* * * * *'),
		);
		$scheduler->addJob(
			new CustomNameJob(
				new CallbackJob(static function () use ($clock): void {
					$clock->move(60);
				}),
				'job1',
			),
			new CronExpression('* * * * *'),
		);

		$summary = $scheduler->run();

		$after = $clock->now();
		self::assertNotEquals($before, $after);
		self::assertEquals(
			new RunSummary(
				$before,
				$after,
				[
					new JobSummary(
						new JobInfo(
							'Tests\Orisai\Scheduler\Doubles\CallbackList::job1()',
							'* * * * *',
							$before,
						),
						new JobResult(new CronExpression('* * * * *'), $before, JobResultState::done()),
					),
					new JobSummary(
						new JobInfo(
							'job1',
							'* * * * *',
							$before,
						),
						new JobResult(new CronExpression('* * * * *'), $after, JobResultState::done()),
					),
				],
			),
			$summary,
		);
	}

	public function testJobSummary(): void
	{
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$job = new CallbackJob(Closure::fromCallable([$cbs, 'job1']));
		$scheduler->addJob($job, new CronExpression('* * * * *'));

		$summary = $scheduler->runJob(0);
		self::assertInstanceOf(JobSummary::class, $summary);

		$now = $clock->now();
		self::assertEquals(
			new JobInfo(
				'Tests\Orisai\Scheduler\Doubles\CallbackList::job1()',
				'* * * * *',
				$now,
			),
			$summary->getInfo(),
		);
		self::assertEquals(
			new JobResult(new CronExpression('* * * * *'), $now, JobResultState::done()),
			$summary->getResult(),
		);
	}

	public function testLockAlreadyAcquired(): void
	{
		$lockFactory = new TestLockFactory(new InMemoryStore(), false);
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, $lockFactory, null, $clock);

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
				new JobSummary(
					new JobInfo('job1', '* * * * *', $clock->now()),
					new JobResult(new CronExpression('* * * * *'), $clock->now(), JobResultState::skip()),
				),
				new JobSummary(
					new JobInfo('job2', '* * * * *', $clock->now()),
					new JobResult(new CronExpression('* * * * *'), $clock->now(), JobResultState::done()),
				),
			],
			$result->getJobs(),
		);
		self::assertSame(
			$result->getJobs()[0]->getInfo()->getStart(),
			$result->getJobs()[0]->getResult()->getEnd(),
		);
		self::assertNotSame(
			$result->getJobs()[1]->getInfo()->getStart(),
			$result->getJobs()[1]->getResult()->getEnd(),
		);

		$scheduler->runJob(0);
		$scheduler->runJob(1);
		self::assertSame(0, $i1);
		self::assertSame(2, $i2);

		$lock->release();

		// Lock was released, job is executed
		$result = $scheduler->run();
		self::assertSame(1, $i1);
		self::assertSame(3, $i2);
		self::assertEquals(
			[
				new JobSummary(
					new JobInfo('job1', '* * * * *', $clock->now()),
					new JobResult(new CronExpression('* * * * *'), $clock->now(), JobResultState::done()),
				),
				new JobSummary(
					new JobInfo('job2', '* * * * *', $clock->now()),
					new JobResult(new CronExpression('* * * * *'), $clock->now(), JobResultState::done()),
				),
			],
			$result->getJobs(),
		);

		$scheduler->runJob(0);
		$scheduler->runJob(1);
		self::assertSame(2, $i1);
		self::assertSame(4, $i2);

		$scheduler->run();
		self::assertSame(3, $i1);
		self::assertSame(5, $i2);

		$scheduler->runJob(0);
		$scheduler->runJob(1);
		self::assertSame(4, $i1);
		self::assertSame(6, $i2);
	}

	public function testLockIsReleasedAfterAnExceptionInJob(): void
	{
		$errorHandler = static function (): void {
			// Noop
		};
		$lockFactory = new TestLockFactory(new InMemoryStore(), false);
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler($errorHandler, $lockFactory, null, $clock);

		$throw = true;
		$i = 0;
		$job = new CallbackJob(
			static function () use (&$i, &$throw): void {
				$i++;
				if ($throw) {
					throw new JobInnerFailure('');
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
		$scheduler = new SimpleScheduler(null, $lockFactory, null, $clock);

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
				throw new JobInnerFailure('');
			}
		});

		$e = null;
		try {
			$scheduler->run();
		} catch (JobInnerFailure $e) {
			// Handled bellow
		}

		self::assertInstanceOf(JobInnerFailure::class, $e);
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
		$scheduler = new SimpleScheduler(null, $lockFactory, null, $clock);

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
				throw new JobInnerFailure('');
			}
		});

		$e = null;
		try {
			$scheduler->run();
		} catch (JobInnerFailure $e) {
			// Handled bellow
		}

		self::assertInstanceOf(JobInnerFailure::class, $e);
		self::assertSame(1, $i);

		// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
		$throw = false;
		$scheduler->run();
		self::assertSame(2, $i);

		$scheduler->run();
		self::assertSame(3, $i);
	}

	public function testProcessExecutorWithErrorHandler(): void
	{
		$scheduler = SchedulerProcessSetup::createWithErrorHandler();
		$summary = $scheduler->run();

		self::assertCount(3, $summary->getJobs());
	}

	public function testProcessExecutorWithoutErrorHandler(): void
	{
		$scheduler = SchedulerProcessSetup::createWithoutErrorHandler();

		$e = null;
		try {
			$scheduler->run();
		} catch (RunFailure $e) {
			// Handled bellow
		}

		self::assertNotNull($e);
		self::assertStringStartsWith(
			<<<'MSG'
Run failed
Suppressed errors:
MSG,
			$e->getMessage(),
		);
		self::assertStringNotContainsString('Could not open input file: bin/console', $e->getMessage());
	}

	public function testProcessExecutorWithDefaultExecutable(): void
	{
		$scheduler = SchedulerProcessSetup::createWithDefaultExecutable();

		$e = null;
		try {
			$scheduler->run();
		} catch (RunFailure $e) {
			// Handled bellow
		}

		self::assertNotNull($e);
		self::assertStringStartsWith(
			<<<'MSG'
Run failed
Suppressed errors:
MSG,
			$e->getMessage(),
		);
		self::assertStringContainsString('Could not open input file: bin/console', $e->getMessage());
	}

}
