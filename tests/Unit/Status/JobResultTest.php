<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use Cron\CronExpression;
use DateTimeImmutable;
use Error;
use Orisai\Scheduler\Status\JobResult;
use PHPUnit\Framework\TestCase;

final class JobResultTest extends TestCase
{

	public function test(): void
	{
		$end = new DateTimeImmutable();

		$result = new JobResult(new CronExpression('* * * * *'), $end, null);
		self::assertSame($end, $result->getEnd());
		self::assertNull($result->getThrowable());
	}

	public function testThrowable(): void
	{
		$throwable = new Error();

		$result = new JobResult(
			new CronExpression('* * * * *'),
			new DateTimeImmutable(),
			$throwable,
		);
		self::assertSame($throwable, $result->getThrowable());
	}

	public function testDatesComputing(): void
	{
		$end = DateTimeImmutable::createFromFormat('U', '10020');
		$result = new JobResult(
			new CronExpression('* * * * *'),
			$end,
			null,
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
