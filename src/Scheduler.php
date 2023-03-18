<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Cron\CronExpression;
use Orisai\Scheduler\Exception\JobFailure;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\RunSummary;

interface Scheduler
{

	/**
	 * @return array<int|string, array{Job, CronExpression}>
	 */
	public function getJobs(): array;

	/**
	 * @throws RunFailure When 1-x jobs failed and no error handler was set
	 */
	public function run(): RunSummary;

	/**
	 * @param string|int $id
	 * @return array{JobInfo, JobResult}|null
	 * @phpstan-return ($force is true ? array{JobInfo, JobResult} : array{JobInfo, JobResult}|null)
	 * @throws JobFailure When job failed and no error handler was set
	 */
	public function runJob($id, bool $force = true): ?array;

}
