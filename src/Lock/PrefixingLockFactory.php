<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Lock;

use Orisai\Exceptions\Logic\NotImplemented;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Wraps LockFactory to prefix all lock resource names.
 * Use when multiple applications share the same lock store to prevent key collisions.
 */
final class PrefixingLockFactory extends LockFactory
{

	private string $prefix;

	public function __construct(PersistingStoreInterface $store, string $prefix)
	{
		parent::__construct($store);
		$this->prefix = $prefix;
	}

	public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
	{
		return parent::createLockFromKey(new Key($this->prefix . $resource), $ttl, $autoRelease);
	}

	public function createLockFromKey(Key $key, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
	{
		$message = "createLockFromKey() is not supported by PrefixingLockFactory because the Key's resource"
			. ' cannot be prefixed after construction. Use createLock() instead.';

		throw NotImplemented::create()
			->withMessage($message);
	}

}
