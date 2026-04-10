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

	/**
	 * @deprecated Always returns true inside a job. Will be removed in v3.0.
	 */
	public function isAcquiredByCurrentProcess(): bool
	{
		return $this->lock->isAcquired();
	}

	public function isExpired(): bool
	{
		return $this->lock->isExpired();
	}

	/**
	 * @deprecated Use extendTo() instead. Will be removed in v3.0.
	 */
	public function refresh(?float $ttl = null): void
	{
		$this->lock->refresh($ttl);
	}

	/**
	 * Extends the lock expiration to given number of seconds from now.
	 * Call periodically in long-running jobs to prevent lock expiry.
	 */
	public function extendTo(float $seconds): void
	{
		$this->lock->refresh($seconds);
	}

	public function getRemainingLifetime(): ?float
	{
		return $this->lock->getRemainingLifetime();
	}

}
