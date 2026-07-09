<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use Closure;
use DateTimeImmutable;
use Generator;
use Orisai\Clock\Clock;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Maintenance\MaintenanceJobSummaryFactory;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\RunParameters;
use Orisai\Scheduler\Status\RunSummary;
use function array_keys;
use function max;

/**
 * @internal
 */
final class BasicJobExecutor implements JobExecutor
{

	private Clock $clock;

	private JobRunner $jobRunner;

	private MaintenanceJobSummaryFactory $maintenanceSummaryFactory;

	public function __construct(Clock $clock, JobRunner $jobRunner)
	{
		$this->clock = $clock;
		$this->jobRunner = $jobRunner;
		$this->maintenanceSummaryFactory = new MaintenanceJobSummaryFactory($clock);
	}

	public function runJobs(
		array $jobSchedulesBySecond,
		DateTimeImmutable $runStart,
		Closure $beforeRunCallback,
		Closure $afterRunCallback,
		?ShutdownCheck $shutdownCheck = null,
		?Closure $onJobEvent = null
	): Generator
	{
		$beforeRunCallback();

		$lastSecond = $jobSchedulesBySecond !== []
			? max(array_keys($jobSchedulesBySecond))
			: 0;

		$jobSummaries = [];
		$suppressedExceptions = [];
		$maintenanceActive = false;
		$skipRemaining = false;

		for ($second = 0; $second <= $lastSecond; $second++) {
			$secondInitiatedAt = $this->clock->now();

			foreach ($jobSchedulesBySecond[$second] ?? [] as $id => $jobSchedule) {
				// Check for shutdown between jobs
				if (!$skipRemaining && $shutdownCheck !== null && $shutdownCheck->shouldShutdown()) {
					$maintenanceActive = true;
					$skipRemaining = true;
				}

				if ($skipRemaining) {
					yield $jobSummaries[] = $this->maintenanceSummaryFactory->create(
						$id,
						$jobSchedule,
						$second,
						$runStart,
					);

					continue;
				}

				$onStarted = $onJobEvent === null
					? null
					: static function (JobInfo $info) use ($onJobEvent, $id, $jobSchedule, $second): void {
						$onJobEvent($id, $jobSchedule, $second, $info);
					};

				[$jobSummary, $throwable] = $this->jobRunner->run(
					$id,
					$jobSchedule,
					new RunParameters($second, false),
					$onStarted,
				);

				yield $jobSummaries[] = $jobSummary;

				if ($throwable !== null) {
					$suppressedExceptions[] = $throwable;
				}
			}

			if (!$skipRemaining) {
				$this->sleepTillNextSecond($second, $lastSecond, $secondInitiatedAt);
			}
		}

		$summary = new RunSummary($runStart, $this->clock->now(), $jobSummaries, $maintenanceActive);

		$afterRunCallback($summary);

		if ($suppressedExceptions !== []) {
			throw RunFailure::create($summary, $suppressedExceptions);
		}

		return $summary;
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
