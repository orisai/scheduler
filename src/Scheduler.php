<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Cron\CronExpression;
use Generator;
use Orisai\Scheduler\Exception\JobFailure;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunParameters;
use Orisai\Scheduler\Status\RunSummary;

interface Scheduler
{

	/**
	 * @return array<int|string, array{Job, CronExpression, int<0, 30>}>
	 */
	public function getScheduledJobs(): array;

	/**
	 * @return Generator<int, JobSummary, void, RunSummary>
	 *
	 * @internal
	 */
	public function runPromise(): Generator;

	/**
	 * @throws RunFailure When 1-x jobs failed and no error handler was set
	 */
	public function run(): RunSummary;

	/**
	 * @param string|int $id
	 * @phpstan-return ($force is true ? JobSummary : JobSummary|null)
	 * @throws JobFailure When job failed and no error handler was set
	 */
	public function runJob($id, bool $force = true, ?RunParameters $parameters = null): ?JobSummary;

}
