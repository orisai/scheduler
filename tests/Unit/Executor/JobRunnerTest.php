<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Executor;

use Cron\CronExpression;
use Exception;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Executor\JobRunner;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\RunParameters;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Throwable;

final class JobRunnerTest extends TestCase
{

	private function createSchedule(?callable $callback = null): JobSchedule
	{
		$job = new CallbackJob(
			$callback !== null
				? static function () use ($callback): void {
					$callback();
				}
				: static function (): void {
				},
		);

		return JobSchedule::create($job, new CronExpression('* * * * *'), 0);
	}

	public function testSuccessfulRunFiresHooksInOrder(): void
	{
		$clock = new FrozenClock(1);
		$runner = new JobRunner(new LockFactory(new InMemoryStore()), $clock, new NullLogger(), null);

		$events = [];
		$schedule = $this->createSchedule(static function () use (&$events): void {
			$events[] = 'job';
		});

		[$summary, $throwable] = $runner->run(
			'id',
			$schedule,
			new RunParameters(0, true),
			static function (JobInfo $info) use (&$events): void {
				$events[] = 'started';
			},
			static function (JobInfo $info, JobResult $result) use (&$events): void {
				$events[] = 'finished';
			},
		);

		self::assertSame(['started', 'job', 'finished'], $events);
		self::assertSame(JobResultState::done(), $summary->getResult()->getState());
		self::assertNull($throwable);
	}

	public function testJobLockSkip(): void
	{
		$lockFactory = new LockFactory(new InMemoryStore());
		$clock = new FrozenClock(1);
		$runner = new JobRunner($lockFactory, $clock, new NullLogger(), null);

		$externalLock = $lockFactory->createLock('Orisai.Scheduler.Job/id');
		self::assertTrue($externalLock->acquire());

		$events = [];
		$schedule = $this->createSchedule(static function () use (&$events): void {
			$events[] = 'job';
		});

		[$summary, $throwable] = $runner->run(
			'id',
			$schedule,
			new RunParameters(0, true),
			static function () use (&$events): void {
				$events[] = 'started';
			},
			static function () use (&$events): void {
				$events[] = 'finished';
			},
		);

		self::assertSame(['finished'], $events);
		self::assertSame(JobResultState::lock(), $summary->getResult()->getState());
		self::assertNull($throwable);
	}

	public function testMinuteLockSkipsSecondRunWithinSameMinute(): void
	{
		$clock = new FrozenClock(1);
		$runner = new JobRunner(new LockFactory(new InMemoryStore()), $clock, new NullLogger(), null);

		$i = 0;
		$schedule = $this->createSchedule(static function () use (&$i): void {
			$i++;
		});

		[$first] = $runner->run('id', $schedule, new RunParameters(0, false));
		[$second] = $runner->run('id', $schedule, new RunParameters(0, false));

		self::assertSame(1, $i);
		self::assertSame(JobResultState::done(), $first->getResult()->getState());
		self::assertSame(JobResultState::lock(), $second->getResult()->getState());
	}

	public function testManualRunBypassesMinuteLock(): void
	{
		$clock = new FrozenClock(1);
		$runner = new JobRunner(new LockFactory(new InMemoryStore()), $clock, new NullLogger(), null);

		$i = 0;
		$schedule = $this->createSchedule(static function () use (&$i): void {
			$i++;
		});

		[$first] = $runner->run('id', $schedule, new RunParameters(0, true));
		[$second] = $runner->run('id', $schedule, new RunParameters(0, true));

		self::assertSame(2, $i);
		self::assertSame(JobResultState::done(), $first->getResult()->getState());
		self::assertSame(JobResultState::done(), $second->getResult()->getState());
	}

	public function testErrorHandlerConsumesThrowable(): void
	{
		$clock = new FrozenClock(1);
		$handled = [];
		$runner = new JobRunner(
			new LockFactory(new InMemoryStore()),
			$clock,
			new NullLogger(),
			static function (Throwable $throwable, JobInfo $info, JobResult $result) use (&$handled): void {
				$handled[] = $throwable;
			},
		);

		$schedule = $this->createSchedule(static function (): void {
			throw new Exception('job failed');
		});

		[$summary, $throwable] = $runner->run('id', $schedule, new RunParameters(0, true));

		self::assertNull($throwable);
		self::assertCount(1, $handled);
		self::assertSame('job failed', $handled[0]->getMessage());
		self::assertSame(JobResultState::fail(), $summary->getResult()->getState());
	}

	public function testThrowableReturnedWithoutErrorHandler(): void
	{
		$clock = new FrozenClock(1);
		$runner = new JobRunner(new LockFactory(new InMemoryStore()), $clock, new NullLogger(), null);

		$schedule = $this->createSchedule(static function (): void {
			throw new Exception('job failed');
		});

		[$summary, $throwable] = $runner->run('id', $schedule, new RunParameters(0, true));

		self::assertNotNull($throwable);
		self::assertSame('job failed', $throwable->getMessage());
		self::assertSame(JobResultState::fail(), $summary->getResult()->getState());
	}

	public function testReleaseExpiredMinuteLocks(): void
	{
		$lockFactory = new LockFactory(new InMemoryStore());
		$clock = new FrozenClock(0);
		$runner = new JobRunner($lockFactory, $clock, new NullLogger(), null);

		$schedule = $this->createSchedule();
		$runner->run('id', $schedule, new RunParameters(0, false));

		$external = $lockFactory->createLock('Orisai.Scheduler.Job.Minute/id/197001010000', 60.0, false);
		self::assertFalse($external->acquire());

		$clock->sleep(60);
		$runner->releaseExpiredMinuteLocks();

		self::assertTrue($external->acquire());
	}

	public function testReleaseExpiredMinuteLocksNotYetExpired(): void
	{
		$lockFactory = new LockFactory(new InMemoryStore());
		// Non-zero start is required - with a zero-valued createdAt, `now - createdAt`
		// and `now + createdAt` are indistinguishable.
		$clock = new FrozenClock(1);
		$runner = new JobRunner($lockFactory, $clock, new NullLogger(), null);

		$schedule = $this->createSchedule();
		$runner->run('id', $schedule, new RunParameters(0, false));

		$external = $lockFactory->createLock('Orisai.Scheduler.Job.Minute/id/197001010000', 60.0, false);

		$clock->sleep(59);
		$runner->releaseExpiredMinuteLocks();
		self::assertFalse($external->acquire());

		$clock->sleep(1);
		$runner->releaseExpiredMinuteLocks();
		self::assertTrue($external->acquire());
	}

	public function testJobLockFailureReleasesMinuteLock(): void
	{
		$lockFactory = new LockFactory(new InMemoryStore());
		$clock = new FrozenClock(0);
		$runner = new JobRunner($lockFactory, $clock, new NullLogger(), null);

		$externalJobLock = $lockFactory->createLock('Orisai.Scheduler.Job/id');
		self::assertTrue($externalJobLock->acquire());

		$schedule = $this->createSchedule();

		[$summary, $throwable] = $runner->run('id', $schedule, new RunParameters(0, false));

		self::assertSame(JobResultState::lock(), $summary->getResult()->getState());
		self::assertNull($throwable);

		$externalMinuteLock = $lockFactory->createLock('Orisai.Scheduler.Job.Minute/id/197001010000', 60.0, false);
		self::assertTrue($externalMinuteLock->acquire());
	}

	public function testMinuteLockIsNotAutoReleasedOnRunnerDestruction(): void
	{
		$lockFactory = new LockFactory(new InMemoryStore());
		$clock = new FrozenClock(0);
		$runner = new JobRunner($lockFactory, $clock, new NullLogger(), null);

		$schedule = $this->createSchedule();
		$runner->run('id', $schedule, new RunParameters(0, false));

		// Not yet expired - lock stays retained in $runner->minuteLocks
		$runner->releaseExpiredMinuteLocks();
		unset($runner);

		$external = $lockFactory->createLock('Orisai.Scheduler.Job.Minute/id/197001010000', 60.0, false);
		self::assertFalse($external->acquire());
	}

}
