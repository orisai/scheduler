<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Cron\CronExpression;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Status\RunSummary;

interface Scheduler
{

	/**
	 * @return list<array{Job, CronExpression}>
	 */
	public function getJobs(): array;

	public function run(): RunSummary;

}
