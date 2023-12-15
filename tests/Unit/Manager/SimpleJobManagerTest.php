<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Manager;

use Cron\CronExpression;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Manager\SimpleJobManager;
use PHPUnit\Framework\TestCase;

final class SimpleJobManagerTest extends TestCase
{

	public function test(): void
	{
		$manager = new SimpleJobManager();
		self::assertSame([], $manager->getJobSchedules());
		self::assertNull($manager->getJobSchedule(0));
		self::assertNull($manager->getJobSchedule('id'));
		self::assertNull($manager->getJobSchedule(42));

		$job1 = new CallbackJob(static function (): void {
			// Noop
		});
		$expression1 = new CronExpression('* * * * *');
		$manager->addJob($job1, $expression1);

		$job2 = clone $job1;
		$expression2 = clone $expression1;
		$manager->addJob($job2, $expression2, 'id', 1);

		self::assertEquals(
			[
				0 => JobSchedule::create($job1, $expression1, 0),
				'id' => JobSchedule::create($job2, $expression2, 1),
			],
			$manager->getJobSchedules(),
		);
		self::assertEquals(JobSchedule::create($job1, $expression1, 0), $manager->getJobSchedule(0));
		self::assertEquals(JobSchedule::create($job2, $expression2, 1), $manager->getJobSchedule('id'));
		self::assertNull($manager->getJobSchedule(42));
	}

}
