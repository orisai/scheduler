<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use Error;
use Orisai\Scheduler\Status\JobResult;
use PHPUnit\Framework\TestCase;

final class JobResultTest extends TestCase
{

	public function test(): void
	{
		$result = new JobResult(null);
		self::assertNull($result->getThrowable());
	}

	public function testThrowable(): void
	{
		$throwable = new Error();

		$result = new JobResult($throwable);
		self::assertSame($throwable, $result->getThrowable());
	}

}
