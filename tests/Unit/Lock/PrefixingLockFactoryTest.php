<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Lock;

use Orisai\Exceptions\Logic\NotImplemented;
use Orisai\Scheduler\Lock\PrefixingLockFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\Store\InMemoryStore;

final class PrefixingLockFactoryTest extends TestCase
{

	public function testPrefixIsolatesLocks(): void
	{
		$store = new InMemoryStore();

		$factoryA = new PrefixingLockFactory($store, 'appA.');
		$factoryB = new PrefixingLockFactory($store, 'appB.');

		// Both apps lock the same resource name
		$lockA = $factoryA->createLock('Orisai.Scheduler.Job/my-job');
		$lockB = $factoryB->createLock('Orisai.Scheduler.Job/my-job');

		// Both can acquire — different prefixed keys
		self::assertTrue($lockA->acquire());
		self::assertTrue($lockB->acquire());

		$lockA->release();
		$lockB->release();
	}

	public function testWithoutPrefixLocksCollide(): void
	{
		$store = new InMemoryStore();

		$factoryA = new PrefixingLockFactory($store, '');
		$factoryB = new PrefixingLockFactory($store, '');

		$lockA = $factoryA->createLock('Orisai.Scheduler.Job/my-job');
		$lockB = $factoryB->createLock('Orisai.Scheduler.Job/my-job');

		// Same key, same store — collision
		self::assertTrue($lockA->acquire());
		self::assertFalse($lockB->acquire());

		$lockA->release();
	}

	public function testCreateLockFromKeyThrows(): void
	{
		$factory = new PrefixingLockFactory(new InMemoryStore(), 'app.');

		$this->expectException(NotImplemented::class);
		$this->expectExceptionMessage('createLockFromKey() is not supported');

		$factory->createLockFromKey(new Key('resource'));
	}

}
