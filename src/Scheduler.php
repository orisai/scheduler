<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Closure;
use Generator;
use Orisai\Scheduler\Exception\JobFailure;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Status\ActivityStatus;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunParameters;
use Orisai\Scheduler\Status\RunSummary;

interface Scheduler
{

	public function getStatus(): ActivityStatus;

	/**
	 * @return array<int|string, JobSchedule>
	 */
	public function getJobSchedules(): array;

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
	 * @param (Closure(JobInfo): void)|null $onJobStarted `@internal` — framework-internal hook
	 *        fired right after the job's lock is acquired, before $job->run(). Used by
	 *        ProcessJobExecutor's subprocess protocol to stream events back to the parent.
	 * @param (Closure(JobInfo, JobResult): void)|null $onJobFinished `@internal` — framework-internal
	 *        hook fired at every runJob() exit (lock fail, success, failure). Used by
	 *        ProcessJobExecutor's subprocess protocol.
	 * @phpstan-return ($force is true ? JobSummary : JobSummary|null)
	 * @throws JobFailure When job failed and no error handler was set
	 */
	public function runJob(
		$id,
		bool $force = true,
		?RunParameters $parameters = null,
		?Closure $onJobStarted = null,
		?Closure $onJobFinished = null
	): ?JobSummary;

}
