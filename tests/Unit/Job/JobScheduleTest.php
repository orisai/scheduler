<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Job;

use Cron\CronExpression;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Job\JobSchedule;
use PHPUnit\Framework\TestCase;

final class JobScheduleTest extends TestCase
{

	public function test(): void
	{
		$job = new CallbackJob(static function (): void {
		});
		$expression = new CronExpression('* * * * *');
		$seconds = 1;

		$schedule = new JobSchedule($job, $expression, $seconds);

		self::assertSame($job, $schedule->getJob());
		self::assertSame($expression, $schedule->getExpression());
		self::assertSame($seconds, $schedule->getRepeatAfterSeconds());
	}

}
