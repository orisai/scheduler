<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use DateTimeImmutable;
use Error;
use Orisai\Scheduler\Status\JobResult;
use PHPUnit\Framework\TestCase;

final class JobResultTest extends TestCase
{

	public function test(): void
	{
		$end = new DateTimeImmutable();

		$result = new JobResult($end, null);
		self::assertSame($end, $result->getEnd());
		self::assertNull($result->getThrowable());
	}

	public function testThrowable(): void
	{
		$throwable = new Error();

		$result = new JobResult(new DateTimeImmutable(), $throwable);
		self::assertSame($throwable, $result->getThrowable());
	}

}
