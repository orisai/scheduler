<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use DateTimeImmutable;
use Orisai\Scheduler\Status\JobInfo;
use PHPUnit\Framework\TestCase;

final class JobInfoTest extends TestCase
{

	public function test(): void
	{
		$name = 'name';
		$expression = '* * * * *';
		$start = new DateTimeImmutable();

		$info = new JobInfo($name, $expression, $start);
		self::assertSame($name, $info->getName());
		self::assertSame($expression, $info->getExpression());
		self::assertSame($start, $info->getStart());
	}

}
