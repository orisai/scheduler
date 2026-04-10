<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use JsonException;
use Orisai\Clock\Adapter\ClockAdapterFactory;
use Orisai\Clock\Clock;
use Orisai\Clock\SystemClock;
use Orisai\Exceptions\Logic\InvalidState;
use Orisai\Exceptions\Message;
use Orisai\Scheduler\Exception\JobProcessFailure;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Maintenance\CreatesMaintenanceJobSummary;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunParameters;
use Orisai\Scheduler\Status\RunSummary;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use function array_merge;
use function assert;
use function is_array;
use function json_decode;
use function json_encode;
use function trim;
use const JSON_THROW_ON_ERROR;

/**
 * @infection-ignore-all
 */
final class ProcessJobExecutor implements JobExecutor
{

	use CreatesMaintenanceJobSummary;

	private Clock $clock;

	private LoggerInterface $logger;

	private string $script = 'bin/console';

	private string $command = 'scheduler:run-job';

	public function __construct(?ClockInterface $clock = null, ?LoggerInterface $logger = null)
	{
		$this->clock = ClockAdapterFactory::create($clock ?? new SystemClock());
		$this->logger = $logger ?? new NullLogger();
	}

	public function setExecutable(string $script, string $command = 'scheduler:run-job'): void
	{
		$this->script = $script;
		$this->command = $command;
	}

	public function runJobs(
		array $jobSchedulesBySecond,
		DateTimeImmutable $runStart,
		Closure $beforeRunCallback,
		Closure $afterRunCallback,
		?ShutdownCheck $shutdownCheck = null
	): Generator
	{
		$finder = new PhpExecutableFinder();
		$binary = $finder->find(false);
		// @codeCoverageIgnoreStart
		if ($binary === false) {
			throw InvalidState::create()
				->withMessage('PHP executable could not be found, subprocess cannot be executed.');
		}

		// @codeCoverageIgnoreEnd
		$phpCommand = array_merge([$binary], $finder->findArguments());

		$beforeRunCallback();

		$jobExecutions = [];
		$jobSummaries = [];
		$suppressedExceptions = [];
		$maintenanceActive = false;

		$shutdownDetectedAt = null;
		$lastShutdownCheckAt = 0.0;
		$lastRefreshAt = 0.0;

		$lastExecutedSecond = -1;
		while ($jobExecutions !== [] || $jobSchedulesBySecond !== []) {
			// Refresh registry (throttled to every 30 seconds)
			if ($shutdownCheck !== null && $shutdownDetectedAt === null) {
				$now = (float) $this->clock->now()->format('U.u');
				if ($now - $lastRefreshAt >= 30.0) {
					$lastRefreshAt = $now;
					$shutdownCheck->refresh();
				}
			}

			// Check for shutdown (throttled to every 1 second)
			if ($shutdownCheck !== null && $shutdownDetectedAt === null) {
				$now = (float) $this->clock->now()->format('U.u');
				if ($now - $lastShutdownCheckAt >= 1.0) {
					$lastShutdownCheckAt = $now;

					if ($shutdownCheck->shouldShutdown()) {
						$shutdownDetectedAt = $now;
						$maintenanceActive = true;

						// Create maintenance summaries for jobs not yet started
						foreach ($jobSchedulesBySecond as $second => $schedules) {
							foreach ($schedules as $id => $jobSchedule) {
								yield $jobSummaries[] = $this->createMaintenanceJobSummary(
									$id,
									$jobSchedule,
									$second,
									$runStart,
								);
							}
						}

						$jobSchedulesBySecond = [];
					}
				}
			}

			// Force-kill remaining processes after grace period
			if ($shutdownDetectedAt !== null && $shutdownCheck !== null && $jobExecutions !== []) {
				$elapsed = (float) $this->clock->now()->format('U.u') - $shutdownDetectedAt;
				if ($elapsed >= $shutdownCheck->getGracePeriodSeconds()) {
					foreach ($jobExecutions as $i => [$execution, $jobSchedule, $jobId]) {
						assert($execution instanceof Process);
						if ($execution->isRunning()) {
							$execution->stop(10);
						}

						unset($jobExecutions[$i]);

						$summary = $this->tryCollectJobSummary($execution, $jobSchedule, $jobId);
						if ($summary === null) {
							// Process was killed before producing output
							$summary = $this->createMaintenanceJobSummary(
								$jobId,
								$jobSchedule,
								0,
								$runStart,
							);
						}

						yield $jobSummaries[] = $summary;
					}

					break;
				}
			}

			// If we have scheduled jobs and are at right second, execute them
			if ($jobSchedulesBySecond !== []) {
				$shouldRunSecond = $this->clock->now()->getTimestamp() - $runStart->getTimestamp();

				while ($lastExecutedSecond < $shouldRunSecond) {
					$currentSecond = $lastExecutedSecond + 1;
					if (isset($jobSchedulesBySecond[$currentSecond])) {
						$jobExecutions = $this->startJobs(
							$phpCommand,
							$jobSchedulesBySecond[$currentSecond],
							$jobExecutions,
							new RunParameters($currentSecond, false),
						);
						unset($jobSchedulesBySecond[$currentSecond]);
					}

					$lastExecutedSecond = $currentSecond;
				}
			}

			// Check running jobs
			foreach ($jobExecutions as $i => [$execution, $jobSchedule, $jobId]) {
				assert($execution instanceof Process);
				if ($execution->isRunning()) {
					continue;
				}

				unset($jobExecutions[$i]);

				$summary = $this->tryCollectJobSummary($execution, $jobSchedule, $jobId);
				if ($summary === null) {
					// Check shutdown directly - SIGINT may have killed the subprocess before
					// the throttled shutdown check had a chance to set $shutdownDetectedAt
					if ($shutdownCheck !== null && $shutdownCheck->shouldShutdown()) {
						$summary = $this->createMaintenanceJobSummary($jobId, $jobSchedule, 0, $runStart);
						$maintenanceActive = true;
					} else {
						$suppressedExceptions[] = $this->createSubprocessFail(
							$execution,
							trim($execution->getOutput()),
							trim($execution->getErrorOutput()),
						);

						continue;
					}
				}

				yield $jobSummaries[] = $summary;
			}

			// Nothing to do, wait
			$this->clock->sleep(0, 1);
		}

		$summary = new RunSummary($runStart, $this->clock->now(), $jobSummaries, $maintenanceActive);

		$afterRunCallback($summary);

		if ($suppressedExceptions !== []) {
			throw RunFailure::create($summary, $suppressedExceptions);
		}

		return $summary;
	}

	/**
	 * @param list<string> $phpCommand
	 * @param array<int|string, JobSchedule> $jobSchedules
	 * @param array<int, array{Process, JobSchedule, int|string}> $jobExecutions
	 * @return array<int, array{Process, JobSchedule, int|string}>
	 */
	private function startJobs(
		array $phpCommand,
		array $jobSchedules,
		array $jobExecutions,
		RunParameters $parameters
	): array
	{
		foreach ($jobSchedules as $id => $jobSchedule) {
			$execution = new Process(
				array_merge($phpCommand, [
					$this->script,
					$this->command,
					$id,
					'--json',
					'--parameters',
					json_encode($parameters->toArray(), JSON_THROW_ON_ERROR),
				]),
			);
			$execution->start();

			$jobExecutions[] = [$execution, $jobSchedule, $id];
		}

		return $jobExecutions;
	}

	/**
	 * Reads process output, parses JSON, logs unexpected stdout/stderr.
	 * Returns null if JSON parsing fails (caller decides how to handle).
	 *
	 * @param int|string $jobId
	 */
	private function tryCollectJobSummary(Process $execution, JobSchedule $jobSchedule, $jobId): ?JobSummary
	{
		$stdout = trim($execution->getOutput());
		$stderr = trim($execution->getErrorOutput());

		try {
			$decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
			assert(is_array($decoded));
		} catch (JsonException $e) {
			return null;
		}

		$unexpectedStdout = $decoded['stdout'];
		if ($unexpectedStdout !== '') {
			$this->logUnexpectedStdout($execution, $jobId, $unexpectedStdout);
		}

		if ($stderr !== '') {
			$this->logUnexpectedStderr($execution, $jobId, $stderr);
		}

		return $this->createSummary($decoded, $jobSchedule);
	}

	/**
	 * @param array<mixed> $raw
	 */
	private function createSummary(array $raw, JobSchedule $jobSchedule): JobSummary
	{
		return new JobSummary(
			new JobInfo(
				$raw['info']['id'],
				$raw['info']['name'],
				$raw['info']['expression'],
				$raw['info']['repeatAfterSeconds'],
				$raw['info']['runSecond'],
				DateTimeImmutable::createFromFormat('U.u', $raw['info']['start'][0])
					->setTimezone(new DateTimeZone($raw['info']['start'][1])),
				$jobSchedule->getTimeZone(),
				$raw['info']['forcedRun'],
			),
			new JobResult(
				$jobSchedule->getExpression(),
				DateTimeImmutable::createFromFormat('U.u', $raw['result']['end'][0])
					->setTimezone(new DateTimeZone($raw['result']['end'][1])),
				JobResultState::from($raw['result']['state']),
				$raw['result']['lockExpired'] ?? false,
			),
		);
	}

	private function createSubprocessFail(Process $execution, string $output, string $errorOutput): JobProcessFailure
	{
		$message = Message::create()
			->withContext("Running job via command {$execution->getCommandLine()}")
			->withProblem('Job subprocess did not correctly write job result to stdout.')
			->with('Tip', 'Check the documentation for troubleshooting guide.')
			->with('Exit code', (string) $execution->getExitCode())
			->with('stdout', $output)
			->with('stderr', $errorOutput);

		return JobProcessFailure::create()
			->withMessage($message);
	}

	/**
	 * @param int|string $jobId
	 */
	private function logUnexpectedStderr(Process $execution, $jobId, string $stderr): void
	{
		$this->logger->warning("Subprocess running job '$jobId' produced unexpected stderr output.", [
			'id' => $jobId,
			'command' => $execution->getCommandLine(),
			'exitCode' => $execution->getExitCode(),
			'stderr' => $stderr,
		]);
	}

	/**
	 * @param int|string $jobId
	 */
	private function logUnexpectedStdout(Process $execution, $jobId, string $stdout): void
	{
		$this->logger->warning("Subprocess running job '$jobId' produced unexpected stdout output.", [
			'id' => $jobId,
			'command' => $execution->getCommandLine(),
			'exitCode' => $execution->getExitCode(),
			'stdout' => $stdout,
		]);
	}

}
