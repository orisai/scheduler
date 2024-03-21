<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Orisai\Scheduler\Executor\JobExecutor;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Manager\SimpleJobManager;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

final class SimpleScheduler extends ManagedScheduler
{

	private SimpleJobManager $jobManager;

	public function __construct(
		?Closure $errorHandler = null,
		?LockFactory $lockFactory = null,
		?JobExecutor $executor = null,
		?ClockInterface $clock = null,
		?LoggerInterface $logger = null
	)
	{
		$this->jobManager = new SimpleJobManager();

		parent::__construct(
			$this->jobManager,
			$errorHandler,
			$lockFactory,
			$executor,
			$clock,
			$logger,
		);
	}

	/**
	 * @param int<0, 30> $repeatAfterSeconds
	 */
	public function addJob(
		Job $job,
		CronExpression $expression,
		?string $id = null,
		int $repeatAfterSeconds = 0,
		?DateTimeZone $timeZone = null
	): void
	{
		$this->jobManager->addJob($job, $expression, $id, $repeatAfterSeconds, $timeZone);
	}

	/**
	 * @param Closure(): Job $jobConstructor
	 * @param int<0, 30> $repeatAfterSeconds
	 */
	public function addLazyJob(
		Closure $jobConstructor,
		CronExpression $expression,
		?string $id = null,
		int $repeatAfterSeconds = 0,
		?DateTimeZone $timeZone = null
	): void
	{
		$this->jobManager->addLazyJob($jobConstructor, $expression, $id, $repeatAfterSeconds, $timeZone);
	}

}
