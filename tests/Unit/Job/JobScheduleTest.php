<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Job;

use Cron\CronExpression;
use DateTimeZone;
use Generator;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Job\JobSchedule;
use PHPUnit\Framework\TestCase;

final class JobScheduleTest extends TestCase
{

	/**
	 * @param int<0, 30> $seconds
	 *
	 * @dataProvider provide
	 */
	public function test(string $expression, int $seconds, ?DateTimeZone $timeZone): void
	{
		$job = new CallbackJob(static function (): void {
		});
		$expression = new CronExpression($expression);

		$schedule = JobSchedule::create($job, $expression, $seconds, $timeZone);

		self::assertSame($job, $schedule->getJob());
		self::assertEquals($expression, $schedule->getExpression());
		self::assertNotSame($expression, $schedule->getExpression());
		self::assertSame($seconds, $schedule->getRepeatAfterSeconds());
		self::assertSame($timeZone, $schedule->getTimeZone());
	}

	/**
	 * @param int<0, 30> $seconds
	 *
	 * @dataProvider provide
	 */
	public function testLazy(string $expression, int $seconds, ?DateTimeZone $timeZone): void
	{
		$ctor = static fn (): CallbackJob => new CallbackJob(static function (): void {
		});
		$expression = new CronExpression($expression);

		$schedule = JobSchedule::createLazy($ctor, $expression, $seconds, $timeZone);

		self::assertInstanceOf(CallbackJob::class, $schedule->getJob());
		self::assertSame($schedule->getJob(), $schedule->getJob());
		self::assertEquals($expression, $schedule->getExpression());
		self::assertNotSame($expression, $schedule->getExpression());
		self::assertSame($seconds, $schedule->getRepeatAfterSeconds());
		self::assertSame($timeZone, $schedule->getTimeZone());
	}

	public function provide(): Generator
	{
		yield ['* * * * *', 1, null];
	}

}
