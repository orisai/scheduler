<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
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

	public function runJobs(array $ids, DateTimeImmutable $runStart): RunSummary
	{
		$summaryJobs = [];
		$suppressed = [];
		foreach ($ids as $id) {
			$pair = $this->jobManager->getPair($id);
			assert($pair !== null);
			[$job, $expression] = $pair;

			[$jobSummary, $throwable] = ($this->runCb)($id, $job, $expression);

			$summaryJobs[] = $jobSummary;
			if ($throwable !== null) {
				$suppressed[] = $throwable;
			}
		}

		$summary = new RunSummary($runStart, $this->clock->now(), $summaryJobs);

		if ($suppressed !== []) {
			throw RunFailure::create($summary, $suppressed);
		}

		return $summary;
	}

}
