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
use function strlen;
use function strpos;
use function substr;
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
		?ShutdownCheck $shutdownCheck = null,
		?Closure $onJobEvent = null
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

		/** @var array<int, SubprocessExecutionState> $jobExecutions */
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
					foreach ($jobExecutions as $i => $state) {
						if ($state->process->isRunning()) {
							$state->process->stop(10);
						}

						// Final drain of subprocess output after stop()
						$this->pollSubprocess($state, $onJobEvent);

						unset($jobExecutions[$i]);

						$summary = $this->tryCollectJobSummary($state);
						if ($summary === null) {
							// Subprocess was killed before emitting `finished`. Reuse the
							// JobInfo from `started` (if received) to preserve executionId
							// pairing with the already-fired beforeJob callback.
							$summary = $state->startedInfo !== null
								? $this->createJobSummaryFromStartedInfo(
									$state->startedInfo,
									$state->schedule,
									JobResultState::maintenance(),
								)
								: $this->createMaintenanceJobSummary(
									$state->id,
									$state->schedule,
									0,
									$runStart,
								);
						}

						$this->logUnexpectedOutputIfAny($state);

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
			foreach ($jobExecutions as $i => $state) {
				// Poll subprocess for new framework events (dispatches `started`)
				$this->pollSubprocess($state, $onJobEvent);

				if ($state->process->isRunning()) {
					continue;
				}

				// Subprocess exited — drain any remaining output (finished event may
				// arrive right before exit and not be visible until after isRunning() flipped).
				$this->pollSubprocess($state, $onJobEvent);

				unset($jobExecutions[$i]);

				$summary = $this->tryCollectJobSummary($state);
				if ($summary === null) {
					// Check shutdown directly - SIGINT may have killed the subprocess before
					// the throttled shutdown check had a chance to set $shutdownDetectedAt
					if ($shutdownCheck !== null && $shutdownCheck->shouldShutdown()) {
						// Reuse startedInfo if received (preserves executionId pairing).
						$summary = $state->startedInfo !== null
							? $this->createJobSummaryFromStartedInfo(
								$state->startedInfo,
								$state->schedule,
								JobResultState::maintenance(),
							)
							: $this->createMaintenanceJobSummary(
								$state->id,
								$state->schedule,
								0,
								$runStart,
							);
						$maintenanceActive = true;
					} elseif ($state->startedInfo !== null) {
						// Subprocess crashed after `started` but before `finished`. beforeJob
						// was already fired for this job — yield a synthetic fail summary so
						// afterJob fires and the pairing invariant holds. Also surface the
						// subprocess-level failure via RunFailure.
						$summary = $this->createJobSummaryFromStartedInfo(
							$state->startedInfo,
							$state->schedule,
							JobResultState::fail(),
						);
						$suppressedExceptions[] = $this->createSubprocessFail(
							$state->process,
							trim($state->process->getOutput()),
							trim($state->process->getErrorOutput()),
						);
					} else {
						// Subprocess died before emitting any event. beforeJob never fired
						// either, so skipping yield keeps the invariant intact.
						$suppressedExceptions[] = $this->createSubprocessFail(
							$state->process,
							trim($state->process->getOutput()),
							trim($state->process->getErrorOutput()),
						);

						continue;
					}
				} elseif ($state->failureEvent !== null) {
					// Job threw in the subprocess and had no errorHandler — surface it as a
					// suppressed exception so runPromise throws RunFailure (parity with
					// BasicJobExecutor behavior, parity with pre-events-protocol behavior).
					$suppressedExceptions[] = $this->createUnhandledJobFailure(
						$state->process,
						$state->failureEvent,
					);
				}

				$this->logUnexpectedOutputIfAny($state);

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
	 * @param array<int, SubprocessExecutionState> $jobExecutions
	 * @return array<int, SubprocessExecutionState>
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
					'--events',
					'--parameters',
					json_encode($parameters->toArray(), JSON_THROW_ON_ERROR),
				]),
			);
			$execution->start();

			$jobExecutions[] = new SubprocessExecutionState($execution, $jobSchedule, $id);
		}

		return $jobExecutions;
	}

	/**
	 * Reads incremental subprocess stdout, parses marker-prefixed JSON lines as
	 * framework events, and dispatches `started` events to $onJobEvent. Non-marker
	 * lines accumulate in the state's unexpectedStdout buffer.
	 *
	 * @param (Closure(int|string, JobSchedule, int<0, max>, JobInfo): void)|null $onJobEvent
	 */
	private function pollSubprocess(SubprocessExecutionState $state, ?Closure $onJobEvent): void
	{
		$state->unparsedBuffer .= $state->process->getIncrementalOutput();

		$marker = SubprocessEventProtocol::EventMarker;
		$markerLen = strlen($marker);

		while (($nlPos = strpos($state->unparsedBuffer, "\n")) !== false) {
			$line = substr($state->unparsedBuffer, 0, $nlPos);
			$state->unparsedBuffer = substr($state->unparsedBuffer, $nlPos + 1);

			if ($line === '') {
				continue;
			}

			if (strpos($line, $marker) !== 0) {
				$state->unexpectedStdout .= $line . "\n";

				continue;
			}

			$json = substr($line, $markerLen);
			try {
				$event = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
			} catch (JsonException $e) {
				// Malformed framework event — treat the whole line as unexpected output
				$state->unexpectedStdout .= $line . "\n";

				continue;
			}

			assert(is_array($event));

			$type = $event['type'] ?? null;
			if ($type === SubprocessEventProtocol::TypeStarted) {
				if (!$state->startedDispatched) {
					$info = $this->buildJobInfo($event['info'], $state->schedule);
					$state->startedInfo = $info;
					if ($onJobEvent !== null) {
						$onJobEvent($state->id, $state->schedule, $info->getRunSecond(), $info);
					}

					$state->startedDispatched = true;
				}
			} elseif ($type === SubprocessEventProtocol::TypeFinished) {
				$state->finishedEvent = $event;
			} elseif ($type === SubprocessEventProtocol::TypeFailure) {
				$state->failureEvent = $event;
			}
		}
	}

	private function tryCollectJobSummary(SubprocessExecutionState $state): ?JobSummary
	{
		$stderr = trim($state->process->getErrorOutput());
		if ($stderr !== '') {
			$this->logUnexpectedStderr($state->process, $state->id, $stderr);
		}

		if ($state->finishedEvent === null) {
			return null;
		}

		return $this->createSummary($state->finishedEvent, $state->schedule);
	}

	private function logUnexpectedOutputIfAny(SubprocessExecutionState $state): void
	{
		$captured = '';
		if ($state->finishedEvent !== null && isset($state->finishedEvent['stdout'])) {
			$captured = (string) $state->finishedEvent['stdout'];
		}

		$combined = $state->unexpectedStdout . $captured;
		if ($combined !== '') {
			$this->logUnexpectedStdout($state->process, $state->id, $combined);
		}
	}

	/**
	 * Builds a synthetic JobSummary reusing the JobInfo from the subprocess's
	 * `started` event. Used when the subprocess exited without emitting `finished`
	 * (maintenance-killed or crashed) — reusing the JobInfo preserves the
	 * `executionId` pairing between beforeJob and afterJob user callbacks.
	 */
	private function createJobSummaryFromStartedInfo(
		JobInfo $startedInfo,
		JobSchedule $jobSchedule,
		JobResultState $state
	): JobSummary
	{
		$timezone = $jobSchedule->getTimeZone();
		$now = $timezone !== null
			? $this->clock->now()->setTimezone($timezone)
			: $this->clock->now();

		$result = new JobResult($jobSchedule->getExpression(), $now, $state);

		return new JobSummary($startedInfo, $result);
	}

	/**
	 * @param array<mixed> $rawInfo
	 */
	private function buildJobInfo(array $rawInfo, JobSchedule $jobSchedule): JobInfo
	{
		return new JobInfo(
			$rawInfo['id'],
			$rawInfo['name'],
			$rawInfo['expression'],
			$rawInfo['repeatAfterSeconds'],
			$rawInfo['runSecond'],
			DateTimeImmutable::createFromFormat('U.u', $rawInfo['start'][0])
				->setTimezone(new DateTimeZone($rawInfo['start'][1])),
			$jobSchedule->getTimeZone(),
			$rawInfo['manualRun'],
		);
	}

	/**
	 * @param array<mixed> $raw
	 */
	private function createSummary(array $raw, JobSchedule $jobSchedule): JobSummary
	{
		return new JobSummary(
			$this->buildJobInfo($raw['info'], $jobSchedule),
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
	 * @param array<mixed> $failureEvent
	 */
	private function createUnhandledJobFailure(Process $execution, array $failureEvent): JobProcessFailure
	{
		$class = isset($failureEvent['class']) ? (string) $failureEvent['class'] : 'Throwable';
		$text = isset($failureEvent['message']) ? (string) $failureEvent['message'] : '';

		$message = Message::create()
			->withContext("Running job via command {$execution->getCommandLine()}")
			->withProblem("Job threw an unhandled $class: $text")
			->with('Tip', 'Register an error handler on the scheduler to handle job failures gracefully.');

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
