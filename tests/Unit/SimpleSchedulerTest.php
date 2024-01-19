<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use ErrorException;
use Exception;
use Generator;
use Orisai\Clock\FrozenClock;
use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Scheduler\Exception\JobFailure;
use Orisai\Scheduler\Exception\JobProcessFailure;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\SimpleScheduler;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunInfo;
use Orisai\Scheduler\Status\RunParameters;
use Orisai\Scheduler\Status\RunSummary;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Store\InMemoryStore;
use Tests\Orisai\Scheduler\Doubles\CallbackList;
use Tests\Orisai\Scheduler\Doubles\CustomNameJob;
use Tests\Orisai\Scheduler\Doubles\JobInnerFailure;
use Tests\Orisai\Scheduler\Doubles\TestLockFactory;
use Throwable;
use function count;
use function rtrim;
use function set_error_handler;
use const E_ALL;
use const E_USER_NOTICE;

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

		self::assertEquals([
			JobSchedule::create($job, $expression, 0),
		], $scheduler->getJobSchedules());

		$scheduler->run();
		self::assertSame(1, $i);

		$scheduler->run();
		self::assertSame(2, $i);

		$scheduler->runJob(0);
		self::assertSame(3, $i);

		$scheduler->runJob(0, false);
		self::assertSame(4, $i);
	}

	public function testLazyJob(): void
	{
		$scheduler = new SimpleScheduler();

		$executed = false;
		$jobConstructor = static function () use (&$executed): Job {
			$executed = true;

			return new CallbackJob(static function (): void {
			});
		};
		$expression = new CronExpression('* * * * *');

		$scheduler->addLazyJob($jobConstructor, $expression);
		$scheduler->addLazyJob($jobConstructor, $expression, null, 1, new DateTimeZone('Europe/Prague'));
		self::assertFalse($executed);

		self::assertEquals(
			$scheduler->getJobSchedules(),
			[
				0 => JobSchedule::createLazy($jobConstructor, $expression, 0),
				1 => JobSchedule::createLazy($jobConstructor, $expression, 1, new DateTimeZone('Europe/Prague')),
			],
		);

		$scheduler->runJob(0);
		self::assertTrue($executed);
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

		self::assertEquals([
			$key => JobSchedule::create($job, $expression, 0),
		], $scheduler->getJobSchedules());

		$scheduler->runJob($key);
		self::assertSame(1, $i);
	}

	public function testNoJobs(): void
	{
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		self::assertSame([], $scheduler->getJobSchedules());

		self::assertEquals(
			new RunSummary($clock->now(), $clock->now(), []),
			$scheduler->run(),
		);

		$this->expectException(InvalidArgument::class);
		$this->expectExceptionMessage(
			<<<'MSG'
Context: Running job with ID '0'
Problem: Job is not registered by scheduler.
Tip: Inspect keys in 'Scheduler->getJobSchedules()' or run command
     'scheduler:list' to find correct job ID.
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

	public function testJobEvents(): void
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
				new JobInfo(0, 'Tests\Orisai\Scheduler\Doubles\CallbackList::exceptionJob()', '* * * * *', 0, 0, $now),
				new JobInfo(1, 'Tests\Orisai\Scheduler\Doubles\CallbackList::job1()', '* * * * *', 0, 0, $now),
			],
			$beforeCollected,
		);
		self::assertCount(2, $beforeCollected);
		self::assertEquals(
			[
				[
					new JobInfo(
						0,
						'Tests\Orisai\Scheduler\Doubles\CallbackList::exceptionJob()',
						'* * * * *',
						0,
						0,
						$now,
					),
					new JobResult(new CronExpression('* * * * *'), $now, JobResultState::fail()),
				],
				[
					new JobInfo(1, 'Tests\Orisai\Scheduler\Doubles\CallbackList::job1()', '* * * * *', 0, 0, $now),
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
			static function () use ($clock): void {
				$clock->sleep(5);
			},
		);
		$scheduler->addJob($job, new CronExpression('* * * * *'));

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
				new JobInfo(
					0,
					"tests/Unit/SimpleSchedulerTest.php:$jobLine",
					'* * * * *',
					0,
					0,
					DateTimeImmutable::createFromFormat('U', '1'),
				),
			],
			$beforeCollected,
		);
		self::assertEquals(
			[
				[
					new JobInfo(
						0,
						"tests/Unit/SimpleSchedulerTest.php:$jobLine",
						'* * * * *',
						0,
						0,
						DateTimeImmutable::createFromFormat('U', '1'),
					),
					new JobResult(
						new CronExpression('* * * * *'),
						DateTimeImmutable::createFromFormat('U', '6'),
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
		$clock->sleep(60);
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
		$clock->sleep(60);
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
				$clock->sleep(60); // Moves time to next minute, next time will job be not ran
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

	public function testBeforeRunEvent(): void
	{
		$clock = new FrozenClock(10, new DateTimeZone('UTC'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$list = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$list, 'job1'])),
			new CronExpression('0-59 * * * *'),
			'one',
			30,
			new DateTimeZone('Europe/Prague'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$list, 'job2'])),
			new CronExpression('* * * * *'),
			'two',
		);
		$scheduler->addJob(
			new CallbackJob(static function (): void {
				throw new Exception('Nope');
			}),
			new CronExpression('* * * 10 *'),
			'not-executed',
		);

		$info = null;
		$scheduler->addBeforeRunCallback(static function (RunInfo $givenInfo) use (&$info): void {
			$info = $givenInfo;
		});
		self::assertNull($info);

		$runStart = $clock->now();
		$scheduler->run();
		self::assertNotNull($info);
		self::assertEquals($runStart, $info->getStart());
		self::assertCount(2, $info->getJobInfos());

		foreach ($info->getJobInfos() as $jobInfo) {
			self::assertContains($jobInfo->getId(), ['one', 'two']);
			if ($jobInfo->getId() === 'one') {
				self::assertSame(
					'Tests\Orisai\Scheduler\Doubles\CallbackList::job1()',
					$jobInfo->getName(),
				);
				self::assertSame('0-59 * * * *', $jobInfo->getExpression());
				self::assertSame(30, $jobInfo->getRepeatAfterSeconds());
				self::assertEquals(
					new DateTimeZone('Europe/Prague'),
					$jobInfo->getEstimatedStartTimes()[0]->getTimezone(),
				);
			} else {
				self::assertSame(
					'Tests\Orisai\Scheduler\Doubles\CallbackList::job2()',
					$jobInfo->getName(),
				);
				self::assertSame('* * * * *', $jobInfo->getExpression());
				self::assertSame(0, $jobInfo->getRepeatAfterSeconds());
				self::assertEquals(
					new DateTimeZone('UTC'),
					$jobInfo->getEstimatedStartTimes()[0]->getTimezone(),
				);
			}
		}
	}

	public function testAfterRunEvent(): void
	{
		$scheduler = new SimpleScheduler();

		$summary = null;
		$scheduler->addAfterRunCallback(static function (RunSummary $givenSummary) use (&$summary): void {
			$summary = $givenSummary;
		});
		self::assertNull($summary);

		$scheduler->run();
		self::assertNotNull($summary);
	}

	public function testAfterRunEventAfterException(): void
	{
		$scheduler = new SimpleScheduler();

		$scheduler->addJob(
			new CallbackJob(static function (): void {
				throw new Exception('');
			}),
			new CronExpression('* * * * *'),
		);

		$summary = null;
		$scheduler->addAfterRunCallback(static function (RunSummary $givenSummary) use (&$summary): void {
			$summary = $givenSummary;
		});
		self::assertNull($summary);

		$exception = null;
		try {
			$scheduler->run();
		} catch (Throwable $exception) {
			// Handled bellow
		}

		self::assertNotNull($summary);
		self::assertNotNull($exception);
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
					$clock->sleep(60);
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
							0,
							'Tests\Orisai\Scheduler\Doubles\CallbackList::job1()',
							'* * * * *',
							0,
							0,
							$before,
						),
						new JobResult(new CronExpression('* * * * *'), $before, JobResultState::done()),
					),
					new JobSummary(
						new JobInfo(
							1,
							'job1',
							'* * * * *',
							0,
							0,
							$before,
						),
						new JobResult(new CronExpression('* * * * *'), $after, JobResultState::done()),
					),
				],
			),
			$summary,
		);
	}

	/**
	 * @param int<0, max> $second
	 *
	 * @dataProvider provideJobSummary
	 */
	public function testJobSummary(?RunParameters $parameters, int $second): void
	{
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$job = new CallbackJob(Closure::fromCallable([$cbs, 'job1']));
		$scheduler->addJob($job, new CronExpression('* * * * *'));

		$summary = $scheduler->runJob(0, true, $parameters);
		self::assertInstanceOf(JobSummary::class, $summary);

		$now = $clock->now();
		self::assertEquals(
			new JobInfo(
				0,
				'Tests\Orisai\Scheduler\Doubles\CallbackList::job1()',
				'* * * * *',
				0,
				$second,
				$now,
			),
			$summary->getInfo(),
		);
		self::assertEquals(
			new JobResult(new CronExpression('* * * * *'), $now, JobResultState::done()),
			$summary->getResult(),
		);
	}

	public function provideJobSummary(): Generator
	{
		yield [null, 0];
		yield [new RunParameters(10), 10];
		yield [new RunParameters(30), 30];
	}

	public function testTimeZoneExecution(): void
	{
		$defaultTz = new DateTimeZone('UTC');
		$otherTz = new DateTimeZone('Europe/Prague');
		$clock = new FrozenClock(0, $defaultTz);

		$scheduler = new SimpleScheduler(null, null, null, $clock);
		$expression = new CronExpression('0 0 * * *');

		$i1 = 0;
		$job1 = new CallbackJob(
			static function () use (&$i1): void {
				$i1++;
			},
		);
		$scheduler->addJob($job1, $expression, 'other', 0, $otherTz);

		$i2 = 0;
		$job2 = new CallbackJob(
			static function () use (&$i2): void {
				$i2++;
			},
		);
		$scheduler->addJob($job2, $expression, 'default', 0, $defaultTz);

		$scheduler->run();
		self::assertSame(0, $i1);
		self::assertSame(1, $i2);

		$scheduler->runJob('other', false);
		$scheduler->runJob('default', false);
		self::assertSame(0, $i1);
		self::assertSame(2, $i2);

		$tzOffset = $otherTz->getOffset($clock->now());
		$clock->sleep(86_400 - $tzOffset);

		$scheduler->run();
		self::assertSame(1, $i1);
		self::assertSame(2, $i2);

		$scheduler->runJob('other', false);
		$scheduler->runJob('default', false);
		self::assertSame(2, $i1);
		self::assertSame(2, $i2);
	}

	public function testTimeZoneInfo(): void
	{
		$defaultTz = new DateTimeZone('UTC');
		$otherTz = new DateTimeZone('Europe/Prague');
		$clock = new FrozenClock(0, $defaultTz);

		$scheduler = new SimpleScheduler(null, null, null, $clock);
		$expression = new CronExpression('* * * * *');

		$job = new CallbackJob(static function (): void {
			// Noop
		});
		$scheduler->addJob($job, $expression, null, 0, $otherTz);

		$summaries = $scheduler->run()->getJobSummaries();
		$summary = $summaries[0];

		$start = $summary->getInfo()->getStart();
		self::assertEquals($otherTz, $start->getTimezone());
		self::assertSame('1970-01-01T01:00:00+01:00', $start->format(DateTimeInterface::ATOM));

		$end = $summary->getResult()->getEnd();
		self::assertEquals($otherTz, $end->getTimezone());
		self::assertSame('1970-01-01T01:00:00+01:00', $end->format(DateTimeInterface::ATOM));
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

		$lock = $lockFactory->createLock('Orisai.Scheduler.Job/0');
		$lock->acquire();

		// Lock is active, job is not executed (but the other one is)
		$result = $scheduler->run();
		self::assertSame(0, $i1);
		self::assertSame(1, $i2);
		self::assertEquals(
			[
				new JobSummary(
					new JobInfo(0, 'job1', '* * * * *', 0, 0, $clock->now()),
					new JobResult(new CronExpression('* * * * *'), $clock->now(), JobResultState::lock()),
				),
				new JobSummary(
					new JobInfo(1, 'job2', '* * * * *', 0, 0, $clock->now()),
					new JobResult(new CronExpression('* * * * *'), $clock->now(), JobResultState::done()),
				),
			],
			$result->getJobSummaries(),
		);
		self::assertSame(
			$result->getJobSummaries()[0]->getInfo()->getStart(),
			$result->getJobSummaries()[0]->getResult()->getEnd(),
		);
		self::assertNotSame(
			$result->getJobSummaries()[1]->getInfo()->getStart(),
			$result->getJobSummaries()[1]->getResult()->getEnd(),
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
					new JobInfo(0, 'job1', '* * * * *', 0, 0, $clock->now()),
					new JobResult(new CronExpression('* * * * *'), $clock->now(), JobResultState::done()),
				),
				new JobSummary(
					new JobInfo(1, 'job2', '* * * * *', 0, 0, $clock->now()),
					new JobResult(new CronExpression('* * * * *'), $clock->now(), JobResultState::done()),
				),
			],
			$result->getJobSummaries(),
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

	public function testLockedJobEvent(): void
	{
		$lockFactory = new TestLockFactory(new InMemoryStore(), false);
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, $lockFactory, null, $clock);

		$now = $clock->now();
		$cbs = new CallbackList();

		$job = new CallbackJob(Closure::fromCallable([$cbs, 'job1']));
		$scheduler->addJob($job, new CronExpression('* * * * *'));

		$afterCollected = [];
		$afterCb = static function (JobInfo $info, JobResult $result) use (&$afterCollected): void {
			$afterCollected[] = [$info, $result];
		};
		$scheduler->addLockedJobCallback($afterCb);

		$scheduler->run();
		$scheduler->runJob(0);
		self::assertCount(0, $afterCollected);

		$lock = $lockFactory->createLock('Orisai.Scheduler.Job/0');
		$lock->acquire();
		$scheduler->run();
		self::assertEquals(
			[
				[
					new JobInfo(
						0,
						'Tests\Orisai\Scheduler\Doubles\CallbackList::job1()',
						'* * * * *',
						0,
						0,
						$now,
					),
					new JobResult(new CronExpression('* * * * *'), $now, JobResultState::lock()),
				],
			],
			$afterCollected,
		);
		self::assertCount(1, $afterCollected);

		$scheduler->runJob(0);
		self::assertCount(2, $afterCollected);
	}

	public function testRepeat(): void
	{
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$i1 = 0;
		$job1 = new CallbackJob(
			static function () use (&$i1): void {
				$i1++;
			},
		);
		$scheduler->addJob(
			$job1,
			new CronExpression('* * * * *'),
			null,
			30,
		);

		$summary = $scheduler->run();
		self::assertSame(2, $i1);
		self::assertCount(2, $summary->getJobSummaries());
		self::assertSame(31, $clock->now()->getTimestamp());

		$i2 = 0;
		$job2 = new CallbackJob(
			static function () use (&$i2): void {
				$i2++;
			},
		);
		$scheduler->addJob(
			$job2,
			new CronExpression('* * * * *'),
			null,
			1,
		);

		$summary = $scheduler->run();
		self::assertSame(4, $i1);
		self::assertSame(60, $i2);
		self::assertCount(62, $summary->getJobSummaries());
		self::assertSame(90, $clock->now()->getTimestamp());
	}

	public function testProcessNoJobs(): void
	{
		$clock = new FrozenClock(1);
		$scheduler = SchedulerProcessSetup::createEmpty();

		self::assertSame([], $scheduler->getJobSchedules());

		self::assertEquals(
			new RunSummary($clock->now(), $clock->now(), []),
			$scheduler->run(),
		);
	}

	public function testProcessExecutorWithErrorHandler(): void
	{
		$scheduler = SchedulerProcessSetup::createWithErrorHandler();
		$summary = $scheduler->run();

		self::assertCount(4, $summary->getJobSummaries());
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

		self::assertNotSame([], $e->getSuppressed());
		foreach ($e->getSuppressed() as $suppressed) {
			self::assertInstanceOf(JobProcessFailure::class, $suppressed);
			self::assertStringMatchesFormat(
				<<<'MSG'
Context: Running job via command %a
Problem: Job subprocess failed.
Tip: Make sure that job is executable by the command and that you have the error
     handler set.
stdout: Could not open input file: bin/console
stderr:
MSG,
				rtrim($suppressed->getMessage()),
			);
		}
	}

	public function testProcessStderr(): void
	{
		$scheduler = SchedulerProcessSetup::createWithStderr();

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

		self::assertNotSame([], $e->getSuppressed());
		foreach ($e->getSuppressed() as $suppressed) {
			self::assertInstanceOf(JobProcessFailure::class, $suppressed);
			self::assertStringMatchesFormat(
				<<<'MSG'
Context: Running job via command %a
Problem: Job subprocess failed.
Tip: Make sure that job is executable by the command and that you have the error
     handler set.
stdout:%c
stderr: error
MSG,
				rtrim($suppressed->getMessage()),
			);
		}
	}

	public function testProcessJobStderr(): void
	{
		$scheduler = SchedulerProcessSetup::createWithStderrJob();

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

		self::assertNotSame([], $e->getSuppressed());
		foreach ($e->getSuppressed() as $suppressed) {
			self::assertInstanceOf(JobProcessFailure::class, $suppressed);
			self::assertStringMatchesFormat(
				<<<'MSG'
Context: Running job via command %a
Problem: Job subprocess produced stderr output.
stderr: job error
MSG,
				rtrim($suppressed->getMessage()),
			);
		}
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testProcessJobStdout(): void
	{
		$errors = [];

		set_error_handler(
			static function (int $errno, string $errstr) use (&$errors): bool {
				$errors[] = [$errno, $errstr];

				return true;
			},
			E_ALL,
		);

		$scheduler = SchedulerProcessSetup::createWithStdoutJob();
		$summary = $scheduler->run();

		self::assertCount(1, $summary->getJobSummaries());
		self::assertCount(1, $errors);
		foreach ($errors as [$code, $message]) {
			self::assertSame(E_USER_NOTICE, $code);
			self::assertStringMatchesFormat(
				<<<'MSG'
Context: Running job via command %a
Problem: Job subprocess produced unsupported stdout output.
stdout:  echo%c
MSG,
				$message,
			);
		}
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testProcessJobStdoutWithStrictErrorHandler(): void
	{
		set_error_handler(
			static function (int $errno, string $errstr): void {
				throw new ErrorException($errstr, $errno);
			},
			E_ALL,
		);

		$scheduler = SchedulerProcessSetup::createWithStdoutJob();

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

		self::assertNotSame([], $e->getSuppressed());
		foreach ($e->getSuppressed() as $suppressed) {
			self::assertInstanceOf(ErrorException::class, $suppressed);
			self::assertStringMatchesFormat(
				<<<'MSG'
Context: Running job via command %a
Problem: Job subprocess produced unsupported stdout output.
stdout:  echo%c
MSG,
				$suppressed->getMessage(),
			);
		}
	}

	public function testProcessBeforeRunEvent(): void
	{
		$scheduler = SchedulerProcessSetup::createWithErrorHandler();

		$info = null;
		$scheduler->addBeforeRunCallback(static function (RunInfo $givenInfo) use (&$info): void {
			$info = $givenInfo;
		});
		self::assertNull($info);

		$scheduler->run();
		self::assertNotNull($info);
		self::assertGreaterThan(0, count($info->getJobInfos()));
	}

	public function testProcessAfterRunEvent(): void
	{
		$scheduler = SchedulerProcessSetup::createEmpty();

		$summary = null;
		$scheduler->addAfterRunCallback(static function (RunSummary $givenSummary) use (&$summary): void {
			$summary = $givenSummary;
		});
		self::assertNull($summary);

		$scheduler->run();
		self::assertNotNull($summary);
	}

	public function testProcessAfterRunEventAfterException(): void
	{
		$scheduler = SchedulerProcessSetup::createWithThrowingJob();

		$summary = null;
		$scheduler->addAfterRunCallback(static function (RunSummary $givenSummary) use (&$summary): void {
			$summary = $givenSummary;
		});
		self::assertNull($summary);

		$exception = null;
		try {
			$scheduler->run();
		} catch (Throwable $exception) {
			// Handled bellow
		}

		self::assertNotNull($summary);
		self::assertNotNull($exception);
	}

}
