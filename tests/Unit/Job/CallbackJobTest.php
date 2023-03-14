<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Job;

use Closure;
use Generator;
use Orisai\Scheduler\Job\CallbackJob;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\Scheduler\Doubles\CallbackList;

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
			'tests/Unit/Job/CallbackJobTest.php:19',
			$job->getName(),
		);

		$job->run();
		self::assertSame(1, $i);

		$job->run();
		self::assertSame(2, $i);
	}

	/**
	 * @param Closure(): void $callback
	 *
	 * @dataProvider provideNames
	 */
	public function testNames(string $name, Closure $callback): void
	{
		$job = new CallbackJob($callback);

		self::assertSame(
			$name,
			$job->getName(),
		);
	}

	public function provideNames(): Generator
	{
		$cbs = new CallbackList();

		yield [
			'Tests\Orisai\Scheduler\Doubles\CallbackList::job1()',
			Closure::fromCallable([$cbs, 'job1']),
		];

		yield [
			'Tests\Orisai\Scheduler\Doubles\CallbackList::staticJob()',
			Closure::fromCallable([CallbackList::class, 'staticJob']),
		];

		yield [
			'Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke()',
			Closure::fromCallable($cbs),
		];

		yield [
			'tests/Doubles/CallbackList.php:32',
			Closure::fromCallable($cbs->getClosure()),
		];

		require_once __DIR__ . '/../../Doubles/functionJob.php';

		yield [
			'Tests\Orisai\Scheduler\Doubles\functionJob',
			Closure::fromCallable('Tests\Orisai\Scheduler\Doubles\functionJob'),
		];
	}

}
