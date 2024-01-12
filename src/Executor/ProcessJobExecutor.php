<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use Generator;
use JsonException;
use Orisai\Clock\Adapter\ClockAdapterFactory;
use Orisai\Clock\Clock;
use Orisai\Clock\SystemClock;
use Orisai\Exceptions\Message;
use Orisai\Scheduler\Exception\JobProcessFailure;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunParameters;
use Orisai\Scheduler\Status\RunSummary;
use Psr\Clock\ClockInterface;
use Symfony\Component\Process\Process;
use Throwable;
use function assert;
use function is_array;
use function json_decode;
use function json_encode;
use function trigger_error;
use function trim;
use const E_USER_NOTICE;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * @infection-ignore-all
 */
final class ProcessJobExecutor implements JobExecutor
{

	private Clock $clock;

	private string $script = 'bin/console';

	private string $command = 'scheduler:run-job';

	public function __construct(?ClockInterface $clock = null)
	{
		$this->clock = ClockAdapterFactory::create($clock ?? new SystemClock());
	}

	public function setExecutable(string $script, string $command = 'scheduler:run-job'): void
	{
		$this->script = $script;
		$this->command = $command;
	}

	public function runJobs(
		array $jobSchedulesBySecond,
		DateTimeImmutable $runStart,
		Closure $afterRunCallback
	): Generator
	{
		$jobExecutions = [];
		$jobSummaries = [];
		$suppressedExceptions = [];

		$lastExecutedSecond = -1;
		while ($jobExecutions !== [] || $jobSchedulesBySecond !== []) {
			// If we have scheduled jobs and are at right second, execute them
			if ($jobSchedulesBySecond !== []) {
				$shouldRunSecond = $this->clock->now()->getTimestamp() - $runStart->getTimestamp();

				while ($lastExecutedSecond < $shouldRunSecond) {
					$currentSecond = $lastExecutedSecond + 1;
					if (isset($jobSchedulesBySecond[$currentSecond])) {
						$jobExecutions = $this->startJobs(
							$jobSchedulesBySecond[$currentSecond],
							$jobExecutions,
							new RunParameters($currentSecond),
						);
						unset($jobSchedulesBySecond[$currentSecond]);
					}

					$lastExecutedSecond = $currentSecond;
				}
			}

			// Check running jobs
			foreach ($jobExecutions as $i => [$execution, $cronExpression]) {
				assert($execution instanceof Process);
				if ($execution->isRunning()) {
					continue;
				}

				unset($jobExecutions[$i]);

				$output = $execution->getOutput();
				$errorOutput = trim($execution->getErrorOutput());

				try {
					$decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
					assert(is_array($decoded));
				} catch (JsonException $e) {
					$suppressedExceptions[] = $this->createSubprocessFail(
						$execution,
						$output,
						$errorOutput,
					);

					continue;
				}

				$stdout = $decoded['stdout'];
				if ($stdout !== '') {
					try {
						$this->triggerUnexpectedStdout($execution, $stdout);
					} catch (Throwable $e) {
						$suppressedExceptions[] = $e;

						continue;
					}
				}

				if ($errorOutput !== '') {
					$suppressedExceptions[] = $this->createStderrFail(
						$execution,
						$errorOutput,
					);
				}

				yield $jobSummaries[] = $this->createSummary($decoded, $cronExpression);
			}

			// Nothing to do, wait
			$this->clock->sleep(0, 1);
		}

		$summary = new RunSummary($runStart, $this->clock->now(), $jobSummaries);

		$afterRunCallback($summary);

		if ($suppressedExceptions !== []) {
			throw RunFailure::create($summary, $suppressedExceptions);
		}

		return $summary;
	}

	/**
	 * @param array<int|string, JobSchedule>             $jobSchedules
	 * @param array<int, array{Process, CronExpression}> $jobExecutions
	 * @return array<int, array{Process, CronExpression}>
	 */
	private function startJobs(array $jobSchedules, array $jobExecutions, RunParameters $parameters): array
	{
		foreach ($jobSchedules as $id => $jobSchedule) {
			$execution = new Process([
				PHP_BINARY,
				$this->script,
				$this->command,
				$id,
				'--json',
				'--parameters',
				json_encode($parameters->toArray(), JSON_THROW_ON_ERROR),
			]);
			$execution->start();

			$jobExecutions[] = [$execution, $jobSchedule->getExpression()];
		}

		return $jobExecutions;
	}

	/**
	 * @param array<mixed> $raw
	 */
	private function createSummary(array $raw, CronExpression $cronExpression): JobSummary
	{
		return new JobSummary(
			new JobInfo(
				$raw['info']['id'],
				$raw['info']['name'],
				$raw['info']['expression'],
				$raw['info']['repeatAfterSeconds'],
				$raw['info']['runSecond'],
				DateTimeImmutable::createFromFormat('U.u', $raw['info']['start']),
			),
			new JobResult(
				$cronExpression,
				DateTimeImmutable::createFromFormat('U.u', $raw['result']['end']),
				JobResultState::from($raw['result']['state']),
			),
		);
	}

	private function createSubprocessFail(Process $execution, string $output, string $errorOutput): JobProcessFailure
	{
		$message = Message::create()
			->withContext("Running job via command {$execution->getCommandLine()}")
			->withProblem('Job subprocess failed.')
			->with('stdout', trim($output))
			->with('stderr', $errorOutput);

		return JobProcessFailure::create()
			->withMessage($message);
	}

	private function createStderrFail(Process $execution, string $errorOutput): JobProcessFailure
	{
		$message = Message::create()
			->withContext("Running job via command {$execution->getCommandLine()}")
			->withProblem('Job subprocess produced stderr output.')
			->with('stderr', $errorOutput);

		return JobProcessFailure::create()
			->withMessage($message);
	}

	private function triggerUnexpectedStdout(Process $execution, string $stdout): void
	{
		$message = Message::create()
			->withContext("Running job via command {$execution->getCommandLine()}")
			->withProblem('Job subprocess produced unsupported stdout output.')
			->with('stdout', $stdout);

		trigger_error($message->toString(), E_USER_NOTICE);
	}

}
