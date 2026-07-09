<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Closure;
use DateTimeImmutable;
use Generator;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\PlannedJobInfo;
use Orisai\Scheduler\Status\RunInfo;
use Orisai\Scheduler\Status\RunSummary;

/**
 * @internal
 */
final class SchedulerCallbacks
{

	/** @var list<Closure(JobInfo, JobResult): void> */
	private array $lockedJobCallbacks = [];

	/** @var list<Closure(JobInfo): void> */
	private array $beforeJobCallbacks = [];

	/** @var list<Closure(JobInfo, JobResult): void> */
	private array $afterJobCallbacks = [];

	/** @var list<Closure(RunInfo): void> */
	private array $beforeRunCallbacks = [];

	/** @var list<Closure(RunSummary): void> */
	private array $afterRunCallbacks = [];

	/**
	 * @param Closure(JobInfo, JobResult): void $callback
	 * @param-later-invoked-callable $callback
	 */
	public function addLockedJob(Closure $callback): void
	{
		$this->lockedJobCallbacks[] = $callback;
	}

	/**
	 * @param Closure(JobInfo): void $callback
	 * @param-later-invoked-callable $callback
	 */
	public function addBeforeJob(Closure $callback): void
	{
		$this->beforeJobCallbacks[] = $callback;
	}

	/**
	 * @param Closure(JobInfo, JobResult): void $callback
	 * @param-later-invoked-callable $callback
	 */
	public function addAfterJob(Closure $callback): void
	{
		$this->afterJobCallbacks[] = $callback;
	}

	/**
	 * @param Closure(RunInfo): void $callback
	 * @param-later-invoked-callable $callback
	 */
	public function addBeforeRun(Closure $callback): void
	{
		$this->beforeRunCallbacks[] = $callback;
	}

	/**
	 * @param Closure(RunSummary): void $callback
	 * @param-later-invoked-callable $callback
	 */
	public function addAfterRun(Closure $callback): void
	{
		$this->afterRunCallbacks[] = $callback;
	}

	public function fireJobStarted(JobInfo $info): void
	{
		foreach ($this->beforeJobCallbacks as $cb) {
			$cb($info);
		}
	}

	public function fireJobFinished(JobInfo $info, JobResult $result): void
	{
		if ($result->getState() === JobResultState::lock()) {
			foreach ($this->lockedJobCallbacks as $cb) {
				$cb($info, $result);
			}
		}

		foreach ($this->afterJobCallbacks as $cb) {
			$cb($info, $result);
		}
	}

	/**
	 * @param array<int|string, JobSchedule> $jobSchedules
	 */
	public function fireBeforeRun(DateTimeImmutable $runStart, array $jobSchedules): void
	{
		if ($this->beforeRunCallbacks === []) {
			return;
		}

		$jobInfos = [];
		foreach ($jobSchedules as $id => $jobSchedule) {
			$job = $jobSchedule->getJob();
			$timezone = $jobSchedule->getTimeZone();
			$jobStart = $timezone !== null
				? $runStart->setTimezone($timezone)
				: $runStart;
			$jobInfos[] = new PlannedJobInfo(
				$id,
				$job->getName(),
				$jobSchedule->getExpression()->getExpression(),
				$jobSchedule->getRepeatAfterSeconds(),
				$jobStart,
				$timezone,
			);
		}

		$info = new RunInfo($runStart, $jobInfos);

		foreach ($this->beforeRunCallbacks as $cb) {
			$cb($info);
		}
	}

	public function fireAfterRun(RunSummary $summary): void
	{
		foreach ($this->afterRunCallbacks as $cb) {
			$cb($summary);
		}
	}

	/**
	 * @param Generator<int, JobSummary, void, RunSummary> $generator
	 * @return Generator<int, JobSummary, void, void>
	 */
	public function wrapGenerator(Generator $generator): Generator
	{
		foreach ($generator as $summary) {
			$this->fireJobFinished($summary->getInfo(), $summary->getResult());

			yield $summary;
		}
	}

}
