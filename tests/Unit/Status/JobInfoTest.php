<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use DateTimeImmutable;
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
		string $extendedExpression
	): void
	{
		$info = new JobInfo($id, $name, $expression, $repeatAfterSeconds, $runSecond, $start);
		self::assertSame($id, $info->getId());
		self::assertSame($name, $info->getName());
		self::assertSame($expression, $info->getExpression());
		self::assertSame($repeatAfterSeconds, $info->getRepeatAfterSeconds());
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
				'start' => $start->format('U.u'),
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
			'* * * * *',
		];

		yield [
			1,
			'other name',
			'* * * * */5',
			10,
			15,
			new DateTimeImmutable(),
			'* * * * */5 / 10',
		];
	}

}
