<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Job;

use Closure;
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

		self::assertSame(
			'Tests\Orisai\Scheduler\Unit\Job\{closure}',
			$job->getName(),
		);

		$job->run();
		self::assertSame(1, $i);

		$job->run();
		self::assertSame(2, $i);
	}

	public function testStaticName(): void
	{
		$job = new CallbackJob(Closure::fromCallable([$this, 'testStaticName']));

		self::assertSame(
			'Tests\Orisai\Scheduler\Unit\Job\CallbackJobTest::testStaticName()',
			$job->getName(),
		);
	}

}
