<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use Cron\CronExpression;
use DateTimeImmutable;
use JsonException;
use Orisai\Clock\SystemClock;
use Orisai\Scheduler\Exception\JobProcessFailure;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunSummary;
use Psr\Clock\ClockInterface;
use Symfony\Component\Process\Process;
use function array_map;
use function assert;
use function escapeshellarg;
use function implode;
use function is_array;
use function json_decode;
use function usleep;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * @infection-ignore-all
 */
final class ProcessJobExecutor implements JobExecutor
{

	private ClockInterface $clock;

	private string $script = 'bin/console';

	private string $command = 'scheduler:run-job';

	public function __construct(?ClockInterface $clock = null)
	{
		$this->clock = $clock ?? new SystemClock();
	}

	public function setExecutable(string $script, string $command = 'scheduler:run-job'): void
	{
		$this->script = $script;
		$this->command = $command;
	}

	public function runJobs(array $ids, DateTimeImmutable $runStart): RunSummary
	{
		$executions = [];
		foreach ($ids as $id) {
			$command = implode(' ', array_map(static fn (string $arg) => escapeshellarg($arg), [
				PHP_BINARY,
				$this->script,
				$this->command,
				$id,
				'--json',
			]));

			$executions[$id] = $execution = Process::fromShellCommandline($command);
			$execution->start();
		}

		$summaryJobs = [];
		$suppressed = [];
		while ($executions !== []) {
			foreach ($executions as $key => $execution) {
				if ($execution->isRunning()) {
					continue;
				}

				unset($executions[$key]);

				$output = $execution->getOutput() . $execution->getErrorOutput();

				try {
					$decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
					assert(is_array($decoded));

					$summaryJobs[] = new JobSummary(
						new JobInfo(
							$decoded['info']['id'],
							$decoded['info']['name'],
							$decoded['info']['expression'],
							DateTimeImmutable::createFromFormat('U.v', $decoded['info']['start']),
						),
						new JobResult(
							new CronExpression($decoded['info']['expression']),
							DateTimeImmutable::createFromFormat('U.v', $decoded['result']['end']),
							JobResultState::from($decoded['result']['state']),
						),
					);
				} catch (JsonException $e) {
					$suppressed[] = JobProcessFailure::create()
						->withMessage("Job subprocess failed with following output:\n$output");
				}
			}

			usleep(1_000);
		}

		$summary = new RunSummary($runStart, $this->clock->now(), $summaryJobs);

		if ($suppressed !== []) {
			throw RunFailure::create($summary, $suppressed);
		}

		return $summary;
	}

}
