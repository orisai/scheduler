<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use Orisai\Scheduler\Status\JobResultState;
use PHPUnit\Framework\TestCase;
use ValueError;

final class JobResultStateTest extends TestCase
{

	public function test(): void
	{
		self::assertSame(1, JobResultState::done()->value);
		self::assertSame('Done', JobResultState::done()->name);
		self::assertSame(2, JobResultState::fail()->value);
		self::assertSame('Fail', JobResultState::fail()->name);
		self::assertSame(3, JobResultState::skip()->value);
		self::assertSame('Skip', JobResultState::skip()->name);

		self::assertSame(
			[
				JobResultState::done(),
				JobResultState::fail(),
				JobResultState::skip(),
			],
			JobResultState::cases(),
		);

		self::assertSame(JobResultState::done(), JobResultState::from(1));
		self::assertSame(JobResultState::done(), JobResultState::tryFrom(1));

		self::assertNull(JobResultState::tryFrom(4));
		$this->expectException(ValueError::class);
		JobResultState::from(4);
	}

}
