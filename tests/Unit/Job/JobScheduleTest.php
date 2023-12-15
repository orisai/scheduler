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

		$schedule = JobSchedule::create($job, $expression, $seconds);

		self::assertSame($job, $schedule->getJob());
		self::assertEquals($expression, $schedule->getExpression());
		self::assertNotSame($expression, $schedule->getExpression());
		self::assertSame($seconds, $schedule->getRepeatAfterSeconds());
	}

	public function testLazy(): void
	{
		$ctor = static fn (): CallbackJob => new CallbackJob(static function (): void {
		});
		$expression = new CronExpression('* * * * *');
		$seconds = 1;

		$schedule = JobSchedule::createLazy($ctor, $expression, $seconds);

		self::assertInstanceOf(CallbackJob::class, $schedule->getJob());
		self::assertSame($schedule->getJob(), $schedule->getJob());
		self::assertEquals($expression, $schedule->getExpression());
		self::assertNotSame($expression, $schedule->getExpression());
		self::assertSame($seconds, $schedule->getRepeatAfterSeconds());
	}

}
