<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit;

use Error;
use Exception;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Scheduler;
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

}
