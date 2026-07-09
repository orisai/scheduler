<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Maintenance;

use Cron\CronExpression;
use DateTimeZone;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Maintenance\MaintenanceJobSummaryFactory;
use Orisai\Scheduler\Status\JobResultState;
use PHPUnit\Framework\TestCase;

final class MaintenanceJobSummaryFactoryTest extends TestCase
{

	public function testCreate(): void
	{
		$clock = new FrozenClock(10);
		$factory = new MaintenanceJobSummaryFactory($clock);

		$job = new CallbackJob(static function (): void {
		});
		$schedule = JobSchedule::create($job, new CronExpression('* * * * *'), 0);

		$runStart = $clock->now();
		$clock->sleep(5);

		$summary = $factory->create('job-id', $schedule, 3, $runStart);

		$info = $summary->getInfo();
		self::assertSame('job-id', $info->getJobId());
		self::assertSame('* * * * *', $info->getExpression());
		self::assertSame(3, $info->getRunSecond());
		self::assertEquals($runStart, $info->getStart());
		self::assertNull($info->getTimeZone());
		self::assertFalse($info->isManualRun());

		$result = $summary->getResult();
		self::assertSame(JobResultState::maintenance(), $result->getState());
		self::assertEquals($clock->now(), $result->getEnd());
	}

	public function testCreateWithTimeZone(): void
	{
		$clock = new FrozenClock(10, new DateTimeZone('UTC'));
		$factory = new MaintenanceJobSummaryFactory($clock);

		$job = new CallbackJob(static function (): void {
		});
		$timeZone = new DateTimeZone('Europe/Prague');
		$schedule = JobSchedule::create($job, new CronExpression('* * * * *'), 0, $timeZone);

		$runStart = $clock->now();

		$summary = $factory->create(1, $schedule, 0, $runStart);

		self::assertSame($timeZone->getName(), $summary->getInfo()->getStart()->getTimezone()->getName());
		self::assertSame($timeZone->getName(), $summary->getResult()->getEnd()->getTimezone()->getName());
	}

}
