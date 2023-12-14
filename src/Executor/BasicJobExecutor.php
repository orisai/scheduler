<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use Generator;
use Orisai\Clock\Clock;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Manager\JobManager;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunSummary;
use Throwable;
use function array_keys;
use function assert;
use function max;

/**
 * @internal
 */
final class BasicJobExecutor implements JobExecutor
{

	private Clock $clock;

	private JobManager $jobManager;

	/** @var Closure(string|int, Job, CronExpression, int<0, max>): array{JobSummary, Throwable|null} */
	private Closure $runCb;

	/**
	 * @param Closure(string|int, Job, CronExpression, int<0, max>): array{JobSummary, Throwable|null} $runCb
	 */
	public function __construct(Clock $clock, JobManager $jobManager, Closure $runCb)
	{
		$this->clock = $clock;
		$this->jobManager = $jobManager;
		$this->runCb = $runCb;
	}

	public function runJobs(array $ids, DateTimeImmutable $runStart): Generator
	{
		$jobSchedulesBySecond = $this->getJobSchedulesBySecond($ids);
		$lastSecond = $jobSchedulesBySecond !== []
			? max(array_keys($jobSchedulesBySecond))
			: 0;

		$jobSummaries = [];
		$suppressedExceptions = [];
		for ($second = 0; $second <= $lastSecond; $second++) {
			$secondInitiatedAt = $this->clock->now();

			foreach ($jobSchedulesBySecond[$second] ?? [] as $id => $jobSchedule) {
				[$jobSummary, $throwable] = ($this->runCb)($id, $jobSchedule->getJob(), $jobSchedule->getExpression(), $second);

				yield $jobSummaries[] = $jobSummary;

				if ($throwable !== null) {
					$suppressedExceptions[] = $throwable;
				}
			}

			$this->sleepTillNextSecond($second, $lastSecond, $secondInitiatedAt);
		}

		$summary = new RunSummary($runStart, $this->clock->now(), $jobSummaries);

		if ($suppressedExceptions !== []) {
			throw RunFailure::create($summary, $suppressedExceptions);
		}

		return $summary;
	}

	/**
	 * @param list<int|string> $ids
	 * @return array<int, array<int|string, JobSchedule>>
	 */
	private function getJobSchedulesBySecond(array $ids): array
	{
		$scheduledJobsBySecond = [];
		foreach ($ids as $id) {
			$jobSchedule = $this->jobManager->getJobSchedule($id);
			assert($jobSchedule !== null);

			$repeatAfterSeconds = $jobSchedule->getRepeatAfterSeconds();

			if ($repeatAfterSeconds === 0) {
				$scheduledJobsBySecond[0][$id] = $jobSchedule;
			} else {
				for ($second = 0; $second <= 59; $second += $repeatAfterSeconds) {
					$scheduledJobsBySecond[$second][$id] = $jobSchedule;
				}
			}
		}

		return $scheduledJobsBySecond;
	}

	/**
	 * More accurate than (float) $dateTime->format('U.u')
	 */
	private function getMicroTimestamp(DateTimeImmutable $dateTime): float
	{
		$seconds = (float) $dateTime->format('U');
		$microseconds = (float) $dateTime->format('u') / 1e6;

		return $seconds + $microseconds;
	}

	private function sleepTillNextSecond(int $second, int $lastSecond, DateTimeImmutable $secondInitiatedAt): void
	{
		$sleepTime = $this->getTimeTillNextSecond($second, $lastSecond, $secondInitiatedAt);
		$this->clock->sleep(0, 0, (int) ($sleepTime * 1e6));
	}

	private function getTimeTillNextSecond(int $second, int $lastSecond, DateTimeImmutable $secondInitiatedAt): float
	{
		if ($second === $lastSecond) {
			return 0;
		}

		$startOfSecond = $this->getMicroTimestamp($secondInitiatedAt);
		$endOfSecond = $this->getMicroTimestamp($this->clock->now());
		$timeElapsed = $endOfSecond - $startOfSecond;

		return 1 - $timeElapsed;
	}

}
