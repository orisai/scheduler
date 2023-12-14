<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Manager;

use Cron\CronExpression;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Manager\CallbackJobManager;
use PHPUnit\Framework\TestCase;

final class CallbackJobManagerTest extends TestCase
{

	public function test(): void
	{
		$manager = new CallbackJobManager();
		self::assertSame([], $manager->getExpressions());
		self::assertSame([], $manager->getJobSchedules());
		self::assertNull($manager->getJobSchedule(0));
		self::assertNull($manager->getJobSchedule('id'));
		self::assertNull($manager->getJobSchedule(42));

		$job1 = new CallbackJob(static function (): void {
			// Noop
		});
		$expression1 = new CronExpression('* * * * *');
		$manager->addJob(static fn (): Job => $job1, $expression1);

		$job2 = clone $job1;
		$expression2 = clone $expression1;
		$manager->addJob(static fn (): Job => $job2, $expression2, 'id', 1);

		self::assertSame(
			[
				0 => $expression1,
				'id' => $expression2,
			],
			$manager->getExpressions(),
		);
		self::assertEquals(
			[
				0 => new JobSchedule($job1, $expression1, 0),
				'id' => new JobSchedule($job2, $expression2, 1),
			],
			$manager->getJobSchedules(),
		);
		self::assertEquals(new JobSchedule($job1, $expression1, 0), $manager->getJobSchedule(0));
		self::assertEquals(new JobSchedule($job2, $expression2, 1), $manager->getJobSchedule('id'));
		self::assertNull($manager->getJobSchedule(42));
	}

}
