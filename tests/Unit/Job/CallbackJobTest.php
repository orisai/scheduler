<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Job;

use Orisai\Scheduler\Job\CallbackJob;
use PHPUnit\Framework\TestCase;

final class CallbackJobTest extends TestCase
{

	public function test(): void
	{
		$i = 0;

		$job = new CallbackJob(
			static function () use (&$i): void {
				$i++;
			},
		);

		$job->run();
		self::assertSame(1, $i);

		$job->run();
		self::assertSame(2, $i);
	}

}
