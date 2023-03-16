<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Job;

use Symfony\Component\Lock\LockInterface;

final class JobLock
{

	private LockInterface $lock;

	public function __construct(LockInterface $lock)
	{
		$this->lock = $lock;
	}

	public function isAcquiredByCurrentProcess(): bool
	{
		return $this->lock->isAcquired();
	}

	public function isExpired(): bool
	{
		return $this->lock->isExpired();
	}

	public function refresh(?float $ttl = null): void
	{
		$this->lock->refresh($ttl);
	}

	public function getRemainingLifetime(): ?float
	{
		return $this->lock->getRemainingLifetime();
	}

}
