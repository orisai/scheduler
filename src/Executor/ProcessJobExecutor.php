<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use Cron\CronExpression;
use DateTimeImmutable;
use Generator;
use JsonException;
use Orisai\Clock\Adapter\ClockAdapterFactory;
use Orisai\Clock\Clock;
use Orisai\Clock\SystemClock;
use Orisai\Scheduler\Exception\JobProcessFailure;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Manager\JobManager;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunParameters;
use Orisai\Scheduler\Status\RunSummary;
use Psr\Clock\ClockInterface;
use Symfony\Component\Process\Process;
use function assert;
use function is_array;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * @infection-ignore-all
 */
final class ProcessJobExecutor implements JobExecutor
{

	private JobManager $jobManager;

	private Clock $clock;

	private string $script = 'bin/console';

	private string $command = 'scheduler:run-job';

	public function __construct(JobManager $jobManager, ?ClockInterface $clock = null)
	{
		$this->jobManager = $jobManager;
		$this->clock = ClockAdapterFactory::create($clock ?? new SystemClock());
	}

	public function setExecutable(string $script, string $command = 'scheduler:run-job'): void
	{
		$this->script = $script;
		$this->command = $command;
	}

	public function runJobs(array $ids, DateTimeImmutable $runStart): Generator
	{
		if ($ids === []) {
			return new RunSummary($runStart, $runStart, []);
		}

		$scheduledJobsBySecond = $this->getScheduledJobsBySecond($ids);

		$jobExecutions = [];
		$jobSummaries = [];
		$suppressedExceptions = [];

		$lastExecutedSecond = -1;
		while ($jobExecutions !== [] || $scheduledJobsBySecond !== []) {
			// If we have scheduled jobs and are at right second, execute them
			if ($scheduledJobsBySecond !== []) {
				$shouldRunSecond = $this->clock->now()->getTimestamp() - $runStart->getTimestamp();

				while ($lastExecutedSecond < $shouldRunSecond) {
					$currentSecond = $lastExecutedSecond + 1;
					if (isset($scheduledJobsBySecond[$currentSecond])) {
						$jobExecutions = $this->startJobs(
							$scheduledJobsBySecond[$currentSecond],
							$jobExecutions,
							new RunParameters($currentSecond),
						);
						unset($scheduledJobsBySecond[$currentSecond]);
					}

					$lastExecutedSecond = $currentSecond;
				}
			}

			// Check running jobs
			foreach ($jobExecutions as $i => $execution) {
				if (!$execution->isRunning()) {
					unset($jobExecutions[$i]);

					$output = $execution->getOutput() . $execution->getErrorOutput();

					try {
						$decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
						assert(is_array($decoded));

						yield $jobSummaries[] = $this->createSummary($decoded);
					} catch (JsonException $e) {
						$suppressedExceptions[] = JobProcessFailure::create()
							->withMessage("Job subprocess failed with following output:\n$output");
					}
				}
			}

			// Nothing to do, wait
			$this->clock->sleep(0, 1);
		}

		$summary = new RunSummary($runStart, $this->clock->now(), $jobSummaries);

		if ($suppressedExceptions !== []) {
			throw RunFailure::create($summary, $suppressedExceptions);
		}

		return $summary;
	}

	/**
	 * @param non-empty-list<int|string> $ids
	 * @return non-empty-array<int, list<array{int|string, Job, CronExpression}>>
	 */
	private function getScheduledJobsBySecond(array $ids): array
	{
		$scheduledJobsBySecond = [];
		foreach ($ids as $id) {
			$scheduledJob = $this->jobManager->getScheduledJob($id);
			assert($scheduledJob !== null);
			[$job, $expression, $repeatAfterSeconds] = $scheduledJob;

			if ($repeatAfterSeconds === 0) {
				$scheduledJobsBySecond[0][] = [$id, $job, $expression];
			} else {
				for ($second = 0; $second <= 59; $second += $repeatAfterSeconds) {
					$scheduledJobsBySecond[$second][] = [$id, $job, $expression];
				}
			}
		}

		// $ids are not empty and for cycle is always run at least once
		assert($scheduledJobsBySecond !== []);

		return $scheduledJobsBySecond;
	}

	/**
	 * @param list<array{int|string, Job, CronExpression}> $scheduledJobs
	 * @param array<int, Process>                          $jobExecutions
	 * @return array<int, Process>
	 */
	private function startJobs(array $scheduledJobs, array $jobExecutions, RunParameters $parameters): array
	{
		foreach ($scheduledJobs as [$id, $job, $expression]) {
			$jobExecutions[] = $execution = new Process([
				PHP_BINARY,
				$this->script,
				$this->command,
				$id,
				'--json',
				'--parameters',
				json_encode($parameters->toArray(), JSON_THROW_ON_ERROR),
			]);
			$execution->start();
		}

		return $jobExecutions;
	}

	/**
	 * @param array<mixed> $raw
	 */
	private function createSummary(array $raw): JobSummary
	{
		return new JobSummary(
			new JobInfo(
				$raw['info']['id'],
				$raw['info']['name'],
				$raw['info']['expression'],
				$raw['info']['second'],
				DateTimeImmutable::createFromFormat('U.u', $raw['info']['start']),
			),
			new JobResult(
				new CronExpression($raw['info']['expression']),
				DateTimeImmutable::createFromFormat('U.u', $raw['result']['end']),
				JobResultState::from($raw['result']['state']),
			),
		);
	}

}
