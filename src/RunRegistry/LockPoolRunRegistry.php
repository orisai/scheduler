<?php declare(strict_types = 1);

namespace Orisai\Scheduler\RunRegistry;

use Orisai\Exceptions\Logic\InvalidState;
use Orisai\Exceptions\Message;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use function time;

final class LockPoolRunRegistry implements RunRegistry
{

	private LockFactory $lockFactory;

	private int $poolSize;

	/** @var array<int, LockInterface> */
	private array $heldLocks = [];

	/** @var array<int, array{runId: string, pid: int, startTimestamp: int}> */
	private array $heldRuns = [];

	public function __construct(LockFactory $lockFactory, int $poolSize = 10)
	{
		$this->lockFactory = $lockFactory;
		$this->poolSize = $poolSize;
	}

	public function register(string $runId, int $pid): void
	{
		for ($slot = 0; $slot < $this->poolSize; $slot++) {
			$lock = $this->lockFactory->createLock($this->getLockKey($slot));

			if ($lock->acquire()) {
				$this->heldLocks[$slot] = $lock;
				$this->heldRuns[$slot] = [
					'runId' => $runId,
					'pid' => $pid,
					'startTimestamp' => time(),
				];

				return;
			}
		}

		$message = Message::create()
			->withContext("Registering scheduler run '$runId'.")
			->withProblem("All $this->poolSize lock pool slots are taken.")
			->with('Tip', 'Increase the pool size in LockPoolRunRegistry constructor.');

		throw InvalidState::create()
			->withMessage($message);
	}

	public function deregister(string $runId): void
	{
		foreach ($this->heldRuns as $slot => $run) {
			if ($run['runId'] === $runId) {
				$this->heldLocks[$slot]->release();
				unset($this->heldLocks[$slot], $this->heldRuns[$slot]);

				return;
			}
		}
	}

	public function refresh(string $runId): void
	{
		foreach ($this->heldRuns as $slot => $run) {
			if ($run['runId'] === $runId) {
				$this->heldLocks[$slot]->refresh();

				return;
			}
		}
	}

	public function getActiveRuns(): array
	{
		$activeRuns = [];

		for ($slot = 0; $slot < $this->poolSize; $slot++) {
			if (isset($this->heldRuns[$slot])) {
				$run = $this->heldRuns[$slot];
				$activeRuns[] = new ActiveRun($run['runId'], $run['pid'], $run['startTimestamp']);

				continue;
			}

			$lock = $this->lockFactory->createLock($this->getLockKey($slot));
			if (!$lock->acquire()) {
				// Slot is held by another process, we don't know its details
				$activeRuns[] = new ActiveRun("slot-$slot");
			} else {
				$lock->release();
			}
		}

		return $activeRuns;
	}

	private function getLockKey(int $slot): string
	{
		return "Orisai.Scheduler.Run/$slot";
	}

}
