<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Manager;

use Cron\CronExpression;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Manager\CallbackJobManager;
use PHPUnit\Framework\TestCase;

final class CallbackJobManagerTest extends TestCase
{

	public function test(): void
	{
		$manager = new CallbackJobManager();
		self::assertSame([], $manager->getExpressions());
		self::assertSame([], $manager->getPairs());
		self::assertNull($manager->getPair(0));
		self::assertNull($manager->getPair('id'));
		self::assertNull($manager->getPair(42));

		$job1 = new CallbackJob(static function (): void {
			// Noop
		});
		$expression1 = new CronExpression('* * * * *');
		$manager->addJob(static fn (): Job => $job1, $expression1);

		$job2 = clone $job1;
		$expression2 = clone $expression1;
		$manager->addJob(static fn (): Job => $job2, $expression2, 'id');

		self::assertSame(
			[
				0 => $expression1,
				'id' => $expression2,
			],
			$manager->getExpressions(),
		);
		self::assertSame(
			[
				0 => [$job1, $expression1],
				'id' => [$job2, $expression2],
			],
			$manager->getPairs(),
		);
		self::assertSame([$job1, $expression1], $manager->getPair(0));
		self::assertSame([$job2, $expression2], $manager->getPair('id'));
		self::assertNull($manager->getPair(42));
	}

}
