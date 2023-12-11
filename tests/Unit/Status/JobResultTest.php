<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use Cron\CronExpression;
use DateTimeImmutable;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use PHPUnit\Framework\TestCase;

final class JobResultTest extends TestCase
{

	public function test(): void
	{
		$end = new DateTimeImmutable();

		$result = new JobResult(new CronExpression('* * * * *'), $end, JobResultState::done());
		self::assertSame($end, $result->getEnd());
		self::assertSame(JobResultState::done(), $result->getState());

		self::assertSame(
			[
				'end' => $end->format('U.u'),
				'state' => JobResultState::done()->value,
			],
			$result->toArray(),
		);
	}

	public function testDatesComputing(): void
	{
		$end = DateTimeImmutable::createFromFormat('U', '10020');
		$result = new JobResult(
			new CronExpression('* * * * *'),
			$end,
			JobResultState::done(),
		);

		self::assertEquals(
			DateTimeImmutable::createFromFormat('U', '10080'),
			$result->getNextRunDate(),
		);
		self::assertEquals(
			DateTimeImmutable::createFromFormat('U', '10200'),
			$result->getNextRunDate(2),
		);
		self::assertEquals(
			[
				DateTimeImmutable::createFromFormat('U', '10080'),
				DateTimeImmutable::createFromFormat('U', '10140'),
				DateTimeImmutable::createFromFormat('U', '10200'),
			],
			$result->getNextRunDates(3),
		);
	}

}
