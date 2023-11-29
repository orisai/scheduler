<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Closure;
use Cron\CronExpression;
use Orisai\Scheduler\Executor\JobExecutor;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Manager\SimpleJobManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

final class SimpleScheduler extends ManagedScheduler
{

	private SimpleJobManager $jobManager;

	public function __construct(
		?Closure $errorHandler = null,
		?LockFactory $lockFactory = null,
		?JobExecutor $executor = null,
		?ClockInterface $clock = null
	)
	{
		$this->jobManager = new SimpleJobManager();

		parent::__construct(
			$this->jobManager,
			$errorHandler,
			$lockFactory,
			$executor,
			$clock,
		);
	}

	/**
	 * @param int<0, 30> $repeatAfterSeconds
	 */
	public function addJob(Job $job, CronExpression $expression, ?string $id = null, int $repeatAfterSeconds = 0): void
	{
		$this->jobManager->addJob($job, $expression, $id, $repeatAfterSeconds);
	}

}
