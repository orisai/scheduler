<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use Closure;
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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;
use function assert;
use function is_array;
use function json_decode;
use function json_encode;
use function trim;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * @infection-ignore-all
 */
final class ProcessJobExecutor implements JobExecutor
{

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
		Closure $afterRunCallback
	): Generator
	{
		$beforeRunCallback();

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
			foreach ($jobExecutions as $i => [$execution, $jobSchedule, $jobId]) {
				assert($execution instanceof Process);
				if ($execution->isRunning()) {
					continue;
				}

				unset($jobExecutions[$i]);

				$stdout = trim($execution->getOutput());
				$stderr = trim($execution->getErrorOutput());

				try {
					$decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
					assert(is_array($decoded));
				} catch (JsonException $e) {
					$suppressedExceptions[] = $this->createSubprocessFail(
						$execution,
						$stdout,
						$stderr,
					);

					continue;
				}

				$unexpectedStdout = $decoded['stdout'];
				if ($unexpectedStdout !== '') {
					$this->logUnexpectedStdout($execution, $jobId, $unexpectedStdout);
				}

				if ($stderr !== '') {
					$this->logUnexpectedStderr($execution, $jobId, $stderr);
				}

				yield $jobSummaries[] = $this->createSummary($decoded, $jobSchedule);
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
	 * @param array<int|string, JobSchedule>                      $jobSchedules
	 * @param array<int, array{Process, JobSchedule, int|string}> $jobExecutions
	 * @return array<int, array{Process, JobSchedule, int|string}>
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

			$jobExecutions[] = [$execution, $jobSchedule, $id];
		}

		return $jobExecutions;
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
				DateTimeImmutable::createFromFormat('U.u e', $raw['info']['start']),
				$jobSchedule->getTimeZone(),
			),
			new JobResult(
				$jobSchedule->getExpression(),
				DateTimeImmutable::createFromFormat('U.u e', $raw['result']['end']),
				JobResultState::from($raw['result']['state']),
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
