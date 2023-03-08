<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use DateTimeImmutable;
use Orisai\Scheduler\Status\JobInfo;
use PHPUnit\Framework\TestCase;

final class JobInfoTest extends TestCase
{

	public function test(): void
	{
		$start = new DateTimeImmutable();

		$info = new JobInfo($start);
		self::assertSame($start, $info->getStart());
	}

}
