<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use DateTimeImmutable;
use Orisai\Scheduler\Status\JobInfo;
use PHPUnit\Framework\TestCase;

final class JobInfoTest extends TestCase
{

	public function test(): void
	{
		$expression = '* * * * *';
		$start = new DateTimeImmutable();

		$info = new JobInfo($expression, $start);
		self::assertSame($expression, $info->getExpression());
		self::assertSame($start, $info->getStart());
	}

}
