<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Orisai\Scheduler\Status\JobInfo;
use PHPUnit\Framework\TestCase;

final class JobInfoTest extends TestCase
{

	/**
	 * @param int|string $id
	 * @param int<0, 30> $repeatAfterSeconds
	 * @param int<0, max> $runSecond
	 *
	 * @dataProvider provide
	 */
	public function test(
		$id,
		string $name,
		string $expression,
		int $repeatAfterSeconds,
		int $runSecond,
		DateTimeImmutable $start,
		?DateTimeZone $timeZone,
		string $extendedExpression
	): void
	{
		$info = new JobInfo($id, $name, $expression, $repeatAfterSeconds, $runSecond, $start, $timeZone);
		self::assertSame($id, $info->getId());
		self::assertSame($name, $info->getName());
		self::assertSame($expression, $info->getExpression());
		self::assertSame($repeatAfterSeconds, $info->getRepeatAfterSeconds());
		self::assertSame($timeZone, $info->getTimeZone());
		self::assertSame($extendedExpression, $info->getExtendedExpression());
		self::assertSame($runSecond, $info->getRunSecond());
		self::assertSame($start, $info->getStart());

		self::assertSame(
			[
				'id' => $id,
				'name' => $name,
				'expression' => $expression,
				'repeatAfterSeconds' => $repeatAfterSeconds,
				'runSecond' => $runSecond,
				'start' => $start->format('U.u e'),
			],
			$info->toArray(),
		);
	}

	public function provide(): Generator
	{
		yield [
			'id',
			'name',
			'* * * * *',
			0,
			0,
			new DateTimeImmutable(),
			null,
			'* * * * *',
		];

		yield [
			1,
			'other name',
			'* * * * */5',
			10,
			15,
			new DateTimeImmutable(),
			null,
			'* * * * */5 / 10',
		];

		yield [
			'dunno',
			'still dunno',
			'* * 6 9 *',
			0,
			2,
			new DateTimeImmutable(),
			new DateTimeZone('Europe/Prague'),
			'* * 6 9 * (Europe/Prague)',
		];

		yield [
			'whatever',
			'whatever but pale blue',
			'* 1 9 8 4',
			30,
			2,
			new DateTimeImmutable(),
			new DateTimeZone('UTC'),
			'* 1 9 8 4 / 30 (UTC)',
		];
	}

}
