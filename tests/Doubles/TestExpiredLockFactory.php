<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Doubles;

use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

final class TestExpiredLockFactory extends LockFactory
{

	public function __construct()
	{
		// Just to satisfy the parent requirements
		parent::__construct(new InMemoryStore());
	}

	public function createLock(string $resource, ?float $ttl = 300.0, ?bool $autoRelease = null): SharedLockInterface
	{
		return $this->createLockFromKey(new Key($resource), $ttl, $autoRelease);
	}

	public function createLockFromKey(Key $key, ?float $ttl = 300.0, ?bool $autoRelease = null): SharedLockInterface
	{
		$lock = new TestLock();
		$lock->isExpired = true;
		$lock->remainingLifetime = 0;

		return $lock;
	}

}
