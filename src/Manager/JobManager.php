<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Manager;

use Cron\CronExpression;
use Orisai\Scheduler\Job\Job;

interface JobManager
{

	/**
	 * @param int|string $id
	 * @return array{Job, CronExpression, int<0, 30>}|null
	 */
	public function getScheduledJob($id): ?array;

	/**
	 * @return array<int|string, array{Job, CronExpression, int<0, 30>}>
	 */
	public function getScheduledJobs(): array;

	/**
	 * @return array<int|string, CronExpression>
	 */
	public function getExpressions(): array;

}
