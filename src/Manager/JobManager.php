<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Manager;

use Cron\CronExpression;
use Orisai\Scheduler\Job\JobSchedule;

interface JobManager
{

	/**
	 * @param int|string $id
	 */
	public function getJobSchedule($id): ?JobSchedule;

	/**
	 * @return array<int|string, JobSchedule>
	 */
	public function getJobSchedules(): array;

	/**
	 * @return array<int|string, CronExpression>
	 */
	public function getExpressions(): array;

}
