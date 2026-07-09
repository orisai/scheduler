<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Orisai\Clock\Clock;
use Orisai\Scheduler\Job\JobLock;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunParameters;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Throwable;

/**
 * @internal
 */
final class JobRunner
{

	private const MinuteLockTtl = 60.0;

	private LockFactory $lockFactory;

	private Clock $clock;

	private LoggerInterface $logger;

	/** @var Closure(Throwable, JobInfo, JobResult): (void)|null */
	private ?Closure $errorHandler;

	/** @var list<array{LockInterface, float}> */
	private array $minuteLocks = [];

	/**
	 * @param Closure(Throwable, JobInfo, JobResult): (void)|null $errorHandler
	 * @param-later-invoked-callable $errorHandler
	 */
	public function __construct(
		LockFactory $lockFactory,
		Clock $clock,
		LoggerInterface $logger,
		?Closure $errorHandler
	)
	{
		$this->lockFactory = $lockFactory;
		$this->clock = $clock;
		$this->logger = $logger;
		$this->errorHandler = $errorHandler;
	}

	/**
	 * @param string|int $id
	 * @param (Closure(JobInfo): void)|null $onStarted
	 * @param (Closure(JobInfo, JobResult): void)|null $onFinished
	 * @return array{JobSummary, Throwable|null}
	 */
	public function run(
		$id,
		JobSchedule $jobSchedule,
		RunParameters $runParameters,
		?Closure $onStarted = null,
		?Closure $onFinished = null
	): array
	{
		$job = $jobSchedule->getJob();
		$expression = $jobSchedule->getExpression();

		$info = new JobInfo(
			$id,
			$job->getName(),
			$expression->getExpression(),
			$jobSchedule->getRepeatAfterSeconds(),
			$runParameters->getSecond(),
			$this->getCurrentTime($jobSchedule),
			$jobSchedule->getTimeZone(),
			$runParameters->isManualRun(),
		);

		// Minute lock prevents re-execution of the same job for the same clock minute.
		// The key includes the minute (UTC `YmdHi`) so different minutes never collide —
		// this lets the worker spawn immediately on startup without the previous minute's
		// lock blocking the next minute's run.
		// 60-second TTL with autoRelease=false — covers the whole clock minute and survives
		// subprocess exit; expires naturally after its minute ends.
		// Skipped for manual runs — manual execution should always work.
		$minuteLock = null;
		if (!$runParameters->isManualRun()) {
			$minuteLock = $this->createMinuteLock(
				$id,
				$info,
				$jobSchedule->getRepeatAfterSeconds(),
				$runParameters->getSecond(),
			);

			if (!$minuteLock->acquire()) {
				return $this->createLockSkipResult($info, $expression, $onFinished);
			}
		}

		// Job lock prevents concurrent execution of the same job.
		$lock = $this->lockFactory->createLock("Orisai.Scheduler.Job/$id");

		if (!$lock->acquire()) {
			if ($minuteLock !== null) {
				$minuteLock->release();
			}

			return $this->createLockSkipResult($info, $expression, $onFinished);
		}

		$throwable = null;
		try {
			if ($onStarted !== null) {
				$onStarted($info);
			}

			try {
				$job->run(new JobLock($lock));
			} catch (Throwable $throwable) {
				// Handled bellow
			}

			$lockExpired = $lock->isExpired();
			if ($lockExpired) {
				$this->logger->warning("Lock of job '$id' expired before the job finished.", [
					'id' => $id,
				]);
			}

			$result = new JobResult(
				$expression,
				$this->getCurrentTime($jobSchedule),
				$throwable === null ? JobResultState::done() : JobResultState::fail(),
				$lockExpired,
			);

			if ($onFinished !== null) {
				$onFinished($info, $result);
			}

			if ($throwable !== null && $this->errorHandler !== null) {
				($this->errorHandler)($throwable, $info, $result);
				$throwable = null;
			}
		} finally {
			$lock->release();
			// Minute lock NOT released — stays in store until TTL expires.
			// Stored to prevent GC and released at end of run via releaseExpiredMinuteLocks().
			if ($minuteLock !== null) {
				$this->minuteLocks[] = [$minuteLock, (float) $this->clock->now()->format('U.u')];
			}
		}

		return [
			new JobSummary($info, $result),
			$throwable,
		];
	}

	public function releaseExpiredMinuteLocks(): void
	{
		$now = (float) $this->clock->now()->format('U.u');
		$remaining = [];

		foreach ($this->minuteLocks as [$lock, $createdAt]) {
			if ($now - $createdAt >= self::MinuteLockTtl) {
				$lock->release();
			} else {
				$remaining[] = [$lock, $createdAt];
			}
		}

		$this->minuteLocks = $remaining;
	}

	/**
	 * @param string|int $id
	 * @param int<0, 30> $repeatAfterSeconds
	 * @param int<0, max> $runSecond
	 */
	private function createMinuteLock($id, JobInfo $info, int $repeatAfterSeconds, int $runSecond): LockInterface
	{
		$minute = $info->getStart()->setTimezone(new DateTimeZone('UTC'))->format('YmdHi');
		$minuteLockKey = $repeatAfterSeconds > 0
			? "Orisai.Scheduler.Job.Minute/$id/$minute/$runSecond"
			: "Orisai.Scheduler.Job.Minute/$id/$minute";

		return $this->lockFactory->createLock($minuteLockKey, self::MinuteLockTtl, false);
	}

	/**
	 * @param (Closure(JobInfo, JobResult): void)|null $onFinished
	 * @return array{JobSummary, null}
	 */
	private function createLockSkipResult(JobInfo $info, CronExpression $expression, ?Closure $onFinished): array
	{
		$result = new JobResult($expression, $info->getStart(), JobResultState::lock());

		if ($onFinished !== null) {
			$onFinished($info, $result);
		}

		return [
			new JobSummary($info, $result),
			null,
		];
	}

	private function getCurrentTime(JobSchedule $schedule): DateTimeImmutable
	{
		$now = $this->clock->now();
		$timezone = $schedule->getTimeZone();

		return $timezone !== null
			? $now->setTimezone($timezone)
			: $now;
	}

}
