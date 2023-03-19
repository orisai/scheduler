<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunSummary;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * @internal
 */
final class BasicJobExecutor implements JobExecutor
{

	private ClockInterface $clock;

	/** @var Closure(string|int): array{Job, CronExpression} */
	private Closure $getJobCb;

	/** @var Closure(string|int, Job, CronExpression): array{JobSummary, Throwable|null} */
	private Closure $runCb;

	/**
	 * @param Closure(string|int): array{Job, CronExpression}                             $getJobCb
	 * @param Closure(string|int, Job, CronExpression): array{JobSummary, Throwable|null} $runCb
	 */
	public function __construct(ClockInterface $clock, Closure $getJobCb, Closure $runCb)
	{
		$this->clock = $clock;
		$this->getJobCb = $getJobCb;
		$this->runCb = $runCb;
	}

	public function runJobs(array $ids, DateTimeImmutable $runStart): RunSummary
	{
		$summaryJobs = [];
		$suppressed = [];
		foreach ($ids as $id) {
			[$job, $expression] = ($this->getJobCb)($id);

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
