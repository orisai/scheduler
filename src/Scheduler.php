<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Cron\CronExpression;
use Orisai\Scheduler\Exception\JobFailure;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunSummary;

interface Scheduler
{

	/**
	 * @return array<int|string, array{Job, CronExpression}>
	 */
	public function getScheduledJobs(): array;

	/**
	 * @throws RunFailure When 1-x jobs failed and no error handler was set
	 */
	public function run(): RunSummary;

	/**
	 * @param string|int $id
	 * @phpstan-return ($force is true ? JobSummary : JobSummary|null)
	 * @throws JobFailure When job failed and no error handler was set
	 */
	public function runJob($id, bool $force = true): ?JobSummary;

}
