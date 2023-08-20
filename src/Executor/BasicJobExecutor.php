<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use Generator;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Manager\JobManager;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunSummary;
use Psr\Clock\ClockInterface;
use Throwable;
use function assert;

/**
 * @internal
 */
final class BasicJobExecutor implements JobExecutor
{

	private ClockInterface $clock;

	private JobManager $jobManager;

	/** @var Closure(string|int, Job, CronExpression): array{JobSummary, Throwable|null} */
	private Closure $runCb;

	/**
	 * @param Closure(string|int, Job, CronExpression): array{JobSummary, Throwable|null} $runCb
	 */
	public function __construct(ClockInterface $clock, JobManager $jobManager, Closure $runCb)
	{
		$this->clock = $clock;
		$this->jobManager = $jobManager;
		$this->runCb = $runCb;
	}

	public function runJobs(array $ids, DateTimeImmutable $runStart): Generator
	{
		$jobSummaries = [];
		$suppressed = [];
		foreach ($ids as $id) {
			$scheduledJob = $this->jobManager->getScheduledJob($id);
			assert($scheduledJob !== null);
			[$job, $expression] = $scheduledJob;

			[$jobSummary, $throwable] = ($this->runCb)($id, $job, $expression);

			yield $jobSummaries[] = $jobSummary;

			if ($throwable !== null) {
				$suppressed[] = $throwable;
			}
		}

		$summary = new RunSummary($runStart, $this->clock->now(), $jobSummaries);

		if ($suppressed !== []) {
			throw RunFailure::create($summary, $suppressed);
		}

		return $summary;
	}

}
