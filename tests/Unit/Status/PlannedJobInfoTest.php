<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use DateTimeImmutable;
use Generator;
use Orisai\Scheduler\Status\PlannedJobInfo;
use PHPUnit\Framework\TestCase;

final class PlannedJobInfoTest extends TestCase
{

	/**
	 * @param int|string $id
	 * @param int<0, 30> $seconds
	 *
	 * @dataProvider provideBasic
	 */
	public function testBasic(
		$id,
		string $name,
		string $expression,
		int $seconds,
		string $extendedExpression,
		DateTimeImmutable $start
	): void
	{
		$info = new PlannedJobInfo($id, $name, $expression, $seconds, $start);

		self::assertSame($id, $info->getId());
		self::assertSame($name, $info->getName());
		self::assertSame($expression, $info->getExpression());
		self::assertSame($seconds, $info->getRepeatAfterSeconds());
		self::assertSame($extendedExpression, $info->getExtendedExpression());
	}

	public function provideBasic(): Generator
	{
		yield [
			'id',
			'name',
			'* * * * *',
			5,
			'* * * * * / 5',
			new DateTimeImmutable(),
		];

		yield [
			0,
			'another name',
			'1 1 1 1 1',
			10,
			'1 1 1 1 1 / 10',
			new DateTimeImmutable('1 month ago'),
		];
	}

	/**
	 * @param int<0, 30> $seconds
	 * @param list<DateTimeImmutable> $estimatedTimes
	 *
	 * @dataProvider provideEstimatedStartTimes
	 */
	public function testEstimatedStartTimes(
		DateTimeImmutable $start,
		int $seconds,
		int $runsCount,
		array $estimatedTimes
	): void
	{
		$info = new PlannedJobInfo('id', 'name', '* * * * *', $seconds, $start);

		self::assertSame($runsCount, $info->getRunsCountPerMinute());
		self::assertEquals($estimatedTimes, $info->getEstimatedStartTimes());
		self::assertSame($info->getEstimatedStartTimes(), $info->getEstimatedStartTimes());
	}

	public function provideEstimatedStartTimes(): Generator
	{
		yield [
			DateTimeImmutable::createFromFormat('U', '0'),
			0,
			1,
			[
				DateTimeImmutable::createFromFormat('U', '0'),
			],
		];

		yield [
			DateTimeImmutable::createFromFormat('U', '10'),
			30,
			2,
			[
				DateTimeImmutable::createFromFormat('U', '10'),
				DateTimeImmutable::createFromFormat('U', '40'),
			],
		];

		yield [
			DateTimeImmutable::createFromFormat('U', '10'),
			29,
			2,
			[
				DateTimeImmutable::createFromFormat('U', '10'),
				DateTimeImmutable::createFromFormat('U', '39'),
			],
		];

		yield [
			DateTimeImmutable::createFromFormat('U', '10'),
			5,
			12,
			[
				DateTimeImmutable::createFromFormat('U', '10'),
				DateTimeImmutable::createFromFormat('U', '15'),
				DateTimeImmutable::createFromFormat('U', '20'),
				DateTimeImmutable::createFromFormat('U', '25'),
				DateTimeImmutable::createFromFormat('U', '30'),
				DateTimeImmutable::createFromFormat('U', '35'),
				DateTimeImmutable::createFromFormat('U', '40'),
				DateTimeImmutable::createFromFormat('U', '45'),
				DateTimeImmutable::createFromFormat('U', '50'),
				DateTimeImmutable::createFromFormat('U', '55'),
				DateTimeImmutable::createFromFormat('U', '60'),
				DateTimeImmutable::createFromFormat('U', '65'),
			],
		];
	}

}
