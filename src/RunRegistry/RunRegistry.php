<?php declare(strict_types = 1);

namespace Orisai\Scheduler\RunRegistry;

interface RunRegistry
{

	public function register(string $runId, int $pid): void;

	public function deregister(string $runId): void;

	public function refresh(string $runId): void;

	/**
	 * @return list<ActiveRun>
	 */
	public function getActiveRuns(): array;

}
