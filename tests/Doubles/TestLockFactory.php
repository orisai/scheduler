<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Doubles;

use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\PersistingStoreInterface;

final class TestLockFactory extends LockFactory
{

	private bool $autoRelease;

	public function __construct(PersistingStoreInterface $store, bool $autoRelease)
	{
		parent::__construct($store);
		$this->autoRelease = $autoRelease;
	}

	public function createLock(string $resource, ?float $ttl = 300.0, ?bool $autoRelease = null): LockInterface
	{
		return parent::createLock($resource, $ttl, $autoRelease ?? $this->autoRelease);
	}

	public function createLockFromKey(Key $key, ?float $ttl = 300.0, ?bool $autoRelease = null): LockInterface
	{
		return parent::createLockFromKey($key, $ttl, $autoRelease ?? $this->autoRelease);
	}

}
