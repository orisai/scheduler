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
		$second = 0;
		$start = new DateTimeImmutable();

		$info = new JobInfo($id, $name, $expression, $second, $start);
		self::assertSame($id, $info->getId());
		self::assertSame($name, $info->getName());
		self::assertSame($expression, $info->getExpression());
		self::assertSame($second, $info->getSecond());
		self::assertSame($start, $info->getStart());

		self::assertSame(
			[
				'id' => $id,
				'name' => $name,
				'expression' => $expression,
				'second' => $second,
				'start' => $start->format('U.v'),
			],
			$info->toArray(),
		);
	}

}
