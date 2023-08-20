<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Manager;

use Cron\CronExpression;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Manager\SimpleJobManager;
use PHPUnit\Framework\TestCase;

final class SimpleJobManagerTest extends TestCase
{

	public function test(): void
	{
		$manager = new SimpleJobManager();
		self::assertSame([], $manager->getExpressions());
		self::assertSame([], $manager->getScheduledJobs());
		self::assertNull($manager->getScheduledJob(0));
		self::assertNull($manager->getScheduledJob('id'));
		self::assertNull($manager->getScheduledJob(42));

		$job1 = new CallbackJob(static function (): void {
			// Noop
		});
		$expression1 = new CronExpression('* * * * *');
		$manager->addJob($job1, $expression1);

		$job2 = clone $job1;
		$expression2 = clone $expression1;
		$manager->addJob($job2, $expression2, 'id');

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
			$manager->getScheduledJobs(),
		);
		self::assertSame([$job1, $expression1], $manager->getScheduledJob(0));
		self::assertSame([$job2, $expression2], $manager->getScheduledJob('id'));
		self::assertNull($manager->getScheduledJob(42));
	}

}
