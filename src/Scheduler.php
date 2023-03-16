<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Cron\CronExpression;
use Orisai\Scheduler\Exception\JobsExecutionFailure;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Status\RunSummary;

interface Scheduler
{

	/**
	 * @return list<array{Job, CronExpression}>
	 */
	public function getJobs(): array;

	/**
	 * @throws JobsExecutionFailure When 1-x jobs failed and no error handler was set
	 */
	public function run(): RunSummary;

}
