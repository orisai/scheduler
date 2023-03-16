<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Job;

use Orisai\Scheduler\Job\JobLock;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\Scheduler\Doubles\TestLock;

final class JobLockTest extends TestCase
{

	public function test(): void
	{
		$lock = new TestLock();

		$jobLock = new JobLock($lock);
		self::assertTrue($jobLock->isAcquiredByCurrentProcess());
		self::assertFalse($jobLock->isExpired());
		self::assertSame(300.0, $jobLock->getRemainingLifetime());
		$jobLock->refresh(60.0);

		self::assertSame(
			[
				['isAcquired'],
				['isExpired'],
				['getRemainingLifetime'],
				['refresh', 60.0],
			],
			$lock->calls,
		);

		$lock->isAcquired = false;
		self::assertFalse($jobLock->isAcquiredByCurrentProcess());

		$lock->isExpired = true;
		self::assertTrue($jobLock->isExpired());

		$lock->remainingLifetime = null;
		self::assertNull($jobLock->getRemainingLifetime());
	}

}
