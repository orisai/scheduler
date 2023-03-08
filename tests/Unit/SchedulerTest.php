<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit;

use DateTimeImmutable;
use Error;
use Exception;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Scheduler;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase
{

	public function testRun(): void
	{
		$scheduler = new Scheduler();

		$i = 0;
		$job = new CallbackJob(
			static function () use (&$i): void {
				$i++;
			},
		);
		$scheduler->addJob($job);

		$scheduler->run();
		self::assertSame(1, $i);

		$scheduler->run();
		self::assertSame(2, $i);
	}

	public function testFailingJob(): void
	{
		$scheduler = new Scheduler();

		$job1 = new CallbackJob(
			static function (): void {
				throw new Exception('test');
			},
		);
		$scheduler->addJob($job1);

		$job2 = new CallbackJob(
			static function (): void {
				throw new Error('test');
			},
		);
		$scheduler->addJob($job2);

		$i = 0;
		$job3 = new CallbackJob(
			static function () use (&$i): void {
				$i++;
			},
		);
		$scheduler->addJob($job3);

		$scheduler->run();
		self::assertSame(1, $i);
	}

	public function testEvents(): void
	{
		$clock = new FrozenClock(1);
		$now = $clock->now();
		$scheduler = new Scheduler($clock);

		$job1 = new CallbackJob(
			static function (): void {
				throw new Exception('test');
			},
		);
		$scheduler->addJob($job1);

		$job2 = new CallbackJob(
			static function (): void {
				// Noop
			},
		);
		$scheduler->addJob($job2);

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
				new JobInfo($now),
				new JobInfo($now),
			],
			$beforeCollected,
		);
		self::assertEquals(
			[
				[
					new JobInfo($now),
					new JobResult($now, new Exception('test')),
				],
				[
					new JobInfo($now),
					new JobResult($now, null),
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
		$scheduler->addJob($job);

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
				new JobInfo(DateTimeImmutable::createFromFormat('U', '1')),
			],
			$beforeCollected,
		);
		self::assertEquals(
			[
				[
					new JobInfo(DateTimeImmutable::createFromFormat('U', '1')),
					new JobResult(
						DateTimeImmutable::createFromFormat('U', '2'),
						null,
					),
				],
			],
			$afterCollected,
		);
	}

}
