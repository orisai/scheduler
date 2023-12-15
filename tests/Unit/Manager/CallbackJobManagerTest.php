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
		self::assertSame([], $manager->getJobSchedules());
		self::assertNull($manager->getJobSchedule(0));
		self::assertNull($manager->getJobSchedule('id'));
		self::assertNull($manager->getJobSchedule(42));

		$job1 = new CallbackJob(static function (): void {
			// Noop
		});
		$job1Ctor = static fn (): Job => $job1;
		$expression1 = new CronExpression('* * * * *');
		$manager->addJob($job1Ctor, $expression1);

		$job2 = clone $job1;
		$job2Ctor = static fn (): Job => $job2;
		$expression2 = clone $expression1;
		$manager->addJob($job2Ctor, $expression2, 'id', 1);

		self::assertEquals(
			[
				0 => JobSchedule::createLazy($job1Ctor, $expression1, 0),
				'id' => JobSchedule::createLazy($job2Ctor, $expression2, 1),
			],
			$manager->getJobSchedules(),
		);
		self::assertEquals(JobSchedule::createLazy($job1Ctor, $expression1, 0), $manager->getJobSchedule(0));
		self::assertEquals(JobSchedule::createLazy($job2Ctor, $expression2, 1), $manager->getJobSchedule('id'));
		self::assertNull($manager->getJobSchedule(42));
	}

}
