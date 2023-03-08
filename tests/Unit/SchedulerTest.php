<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit;

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

}
