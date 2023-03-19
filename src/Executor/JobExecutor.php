<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use DateTimeImmutable;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Status\RunSummary;

interface JobExecutor
{

	/**
	 * @param list<int|string> $ids
	 * @throws RunFailure
	 */
	public function runJobs(array $ids, DateTimeImmutable $runStart): RunSummary;

}
