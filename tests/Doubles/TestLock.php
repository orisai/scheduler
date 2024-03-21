<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Doubles;

use Symfony\Component\Lock\SharedLockInterface;

final class TestLock implements SharedLockInterface
{

	/** @var list<mixed> */
	public array $calls = [];

	public bool $isAcquired = true;

	public bool $isExpired = false;

	public ?float $remainingLifetime = 300.0;

	public function acquire(bool $blocking = false): bool
	{
		$this->calls[] = ['acquire', $blocking];

		return true;
	}

	public function acquireRead(bool $blocking = false): bool
	{
		$this->calls[] = ['acquireRead', $blocking];

		return true;
	}

	public function refresh(?float $ttl = null): void
	{
		$this->calls[] = ['refresh', $ttl];
	}

	public function isAcquired(): bool
	{
		$this->calls[] = ['isAcquired'];

		return $this->isAcquired;
	}

	public function release(): void
	{
		$this->calls[] = ['release'];
	}

	public function isExpired(): bool
	{
		$this->calls[] = ['isExpired'];

		return $this->isExpired;
	}

	public function getRemainingLifetime(): ?float
	{
		$this->calls[] = ['getRemainingLifetime'];

		return $this->remainingLifetime;
	}

}
