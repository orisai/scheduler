<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use DateTimeImmutable;
use Orisai\Scheduler\Status\JobInfo;
use PHPUnit\Framework\TestCase;

final class JobInfoTest extends TestCase
{

	public function test(): void
	{
		$id = 'id';
		$name = 'name';
		$expression = '* * * * *';
		$repeatAfterSeconds = 2;
		$runSecond = 0;
		$start = new DateTimeImmutable();

		$info = new JobInfo($id, $name, $expression, $repeatAfterSeconds, $runSecond, $start);
		self::assertSame($id, $info->getId());
		self::assertSame($name, $info->getName());
		self::assertSame($expression, $info->getExpression());
		self::assertSame($repeatAfterSeconds, $info->getRepeatAfterSeconds());
		self::assertSame("$expression / $repeatAfterSeconds", $info->getExtendedExpression());
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

}
