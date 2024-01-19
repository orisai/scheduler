<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use Orisai\Scheduler\Status\JobResultState;
use PHPUnit\Framework\TestCase;
use ValueError;

final class JobResultStateTest extends TestCase
{

	public function test(): void
	{
		self::assertSame('done', JobResultState::done()->value);
		self::assertSame('Done', JobResultState::done()->name);
		self::assertSame('fail', JobResultState::fail()->value);
		self::assertSame('Fail', JobResultState::fail()->name);
		self::assertSame('lock', JobResultState::lock()->value);
		self::assertSame('Lock', JobResultState::lock()->name);

		self::assertSame(
			[
				JobResultState::done(),
				JobResultState::fail(),
				JobResultState::lock(),
			],
			JobResultState::cases(),
		);

		self::assertSame(JobResultState::done(), JobResultState::from('done'));
		self::assertSame(JobResultState::done(), JobResultState::tryFrom('done'));

		self::assertNull(JobResultState::tryFrom('nonexistent'));
		$this->expectException(ValueError::class);
		JobResultState::from('nonexistent');
	}

}
