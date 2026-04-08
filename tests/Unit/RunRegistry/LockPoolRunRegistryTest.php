<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\RunRegistry;

use Orisai\Exceptions\Logic\InvalidState;
use Orisai\Scheduler\RunRegistry\ActiveRun;
use Orisai\Scheduler\RunRegistry\LockPoolRunRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use function array_map;
use function getmypid;

final class LockPoolRunRegistryTest extends TestCase
{

	public function testRegisterAndDeregister(): void
	{
		$factory = new LockFactory(new InMemoryStore());
		$registry = new LockPoolRunRegistry($factory, 3);

		self::assertSame([], $registry->getActiveRuns());

		$registry->register('run-1', getmypid());
		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('run-1', $runs[0]->getId());

		$registry->register('run-2', 456);
		$runs = $registry->getActiveRuns();
		self::assertCount(2, $runs);
		$runIds = array_map(static fn (ActiveRun $r): string => $r->getId(), $runs);
		self::assertContains('run-1', $runIds);
		self::assertContains('run-2', $runIds);

		$registry->deregister('run-1');
		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('run-2', $runs[0]->getId());

		$registry->deregister('run-2');
		self::assertSame([], $registry->getActiveRuns());
	}

	public function testDeregisterReleasesLock(): void
	{
		$factory = new LockFactory(new InMemoryStore());
		$registry = new LockPoolRunRegistry($factory, 1);

		$registry->register('run-1', getmypid());
		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('run-1', $runs[0]->getId());

		$registry->deregister('run-1');
		self::assertSame([], $registry->getActiveRuns());

		// After deregister, slot is available again
		$registry->register('run-2', 456);
		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('run-2', $runs[0]->getId());

		$registry->deregister('run-2');
	}

	public function testPoolExhaustion(): void
	{
		$factory = new LockFactory(new InMemoryStore());
		$registry = new LockPoolRunRegistry($factory, 2);

		$registry->register('run-1', getmypid());
		$registry->register('run-2', 456);

		$this->expectException(InvalidState::class);
		$this->expectExceptionMessage('All 2 lock pool slots are taken');
		$registry->register('run-3', 789);
	}

	public function testDeregisterNonexistent(): void
	{
		$factory = new LockFactory(new InMemoryStore());
		$registry = new LockPoolRunRegistry($factory, 3);

		$registry->register('run-1', getmypid());
		$registry->deregister('nonexistent');

		// run-1 should still be active
		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('run-1', $runs[0]->getId());

		$registry->deregister('run-1');
	}

	public function testGetActiveRunsProbesAllSlots(): void
	{
		$factory = new LockFactory(new InMemoryStore());
		$registry = new LockPoolRunRegistry($factory, 3);

		$registry->register('run-1', getmypid());
		$registry->register('run-2', 456);

		// All 3 slots are probed: 2 held by us, 1 free (acquired and released)
		$runs = $registry->getActiveRuns();
		self::assertCount(2, $runs);
		$runIds = array_map(static fn (ActiveRun $r): string => $r->getId(), $runs);
		self::assertContains('run-1', $runIds);
		self::assertContains('run-2', $runIds);

		$registry->deregister('run-1');
		$registry->deregister('run-2');

		// All slots now free
		self::assertSame([], $registry->getActiveRuns());
	}

	public function testGetActiveRunsDetectsExternallyHeldSlot(): void
	{
		$factory = new LockFactory(new InMemoryStore());
		$registry = new LockPoolRunRegistry($factory, 3);

		// Externally acquire slot 0's lock (simulates another process holding it)
		$externalLock = $factory->createLock('Orisai.Scheduler.Run/0');
		$externalLock->acquire();

		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('slot-0', $runs[0]->getId());

		// Register our own run - takes slot 1 (slot 0 is taken)
		$registry->register('run-1', getmypid());

		$runs = $registry->getActiveRuns();
		self::assertCount(2, $runs);
		$runIds = array_map(static fn (ActiveRun $r): string => $r->getId(), $runs);
		self::assertContains('slot-0', $runIds);
		self::assertContains('run-1', $runIds);

		$externalLock->release();
		$registry->deregister('run-1');
	}

	public function testRefreshExtendsLock(): void
	{
		$store = new InMemoryStore();
		$factory = new LockFactory($store);
		$registry = new LockPoolRunRegistry($factory, 3);

		$registry->register('run-1', getmypid());

		// Refresh should not throw and should keep the registration alive
		$registry->refresh('run-1');

		$runs = $registry->getActiveRuns();
		self::assertCount(1, $runs);
		self::assertSame('run-1', $runs[0]->getId());

		$registry->deregister('run-1');
	}

	public function testRefreshNonexistentRunDoesNothing(): void
	{
		$factory = new LockFactory(new InMemoryStore());
		$registry = new LockPoolRunRegistry($factory, 3);

		// Should not throw
		$registry->refresh('nonexistent');

		self::assertSame([], $registry->getActiveRuns());
	}

	public function testPoolSizeDefaultIsTen(): void
	{
		$factory = new LockFactory(new InMemoryStore());
		$registry = new LockPoolRunRegistry($factory);

		// Register 10 runs (default pool size)
		for ($i = 0; $i < 10; $i++) {
			$registry->register("run-$i", 100 + $i);
		}

		self::assertCount(10, $registry->getActiveRuns());

		// 11th should fail
		$this->expectException(InvalidState::class);
		$registry->register('run-10', 110);
	}

}
