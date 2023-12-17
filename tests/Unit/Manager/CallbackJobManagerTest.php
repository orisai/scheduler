<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Manager;

use Cron\CronExpression;
use DateTimeZone;
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
		$manager->addJob($job2Ctor, $expression2, 'id', 1, new DateTimeZone('Europe/Prague'));

		$schedule1 = JobSchedule::createLazy($job1Ctor, $expression1, 0);
		$schedule2 = JobSchedule::createLazy($job2Ctor, $expression2, 1, new DateTimeZone('Europe/Prague'));

		self::assertEquals(
			[
				0 => $schedule1,
				'id' => $schedule2,
			],
			$manager->getJobSchedules(),
		);
		self::assertEquals($schedule1, $manager->getJobSchedule(0));
		self::assertEquals($schedule2, $manager->getJobSchedule('id'));
		self::assertNull($manager->getJobSchedule(42));
		self::assertSame($manager->getJobSchedule(0), $manager->getJobSchedule(0));
	}

}
