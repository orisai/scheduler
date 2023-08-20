<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use DateTimeImmutable;
use Generator;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunSummary;

interface JobExecutor
{

	/**
	 * @param list<int|string> $ids
	 * @return Generator<int, JobSummary, void, RunSummary>
	 * @throws RunFailure
	 */
	public function runJobs(array $ids, DateTimeImmutable $runStart): Generator;

}
