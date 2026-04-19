<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Closure;
use Orisai\Clock\SystemClock;
use Orisai\Scheduler\Exception\JobFailure;
use Orisai\Scheduler\Executor\SubprocessEventProtocol;
use Orisai\Scheduler\Scheduler;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunParameters;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use function fflush;
use function fwrite;
use function get_class;
use function json_decode;
use function json_encode;
use function ob_end_clean;
use function ob_get_clean;
use function ob_get_contents;
use function ob_start;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const STDOUT;

final class RunJobCommand extends BaseRunCommand
{

	private Scheduler $scheduler;

	public function __construct(Scheduler $scheduler, ?ClockInterface $clock = null)
	{
		parent::__construct($clock ?? new SystemClock());
		$this->scheduler = $scheduler;
	}

	public static function getDefaultName(): string
	{
		return 'scheduler:run-job';
	}

	public static function getDefaultDescription(): string
	{
		return 'Run single job, ignoring scheduled time';
	}

	protected function configure(): void
	{
		$this->addArgument('id', InputArgument::REQUIRED, 'Job ID (visible in scheduler:list)');
		$this->addOption(
			'no-force',
			null,
			InputOption::VALUE_NONE,
			'Don\'t force job to run and respect due time instead',
		);
		$this->addOption('json', null, InputOption::VALUE_NONE, 'Output in json format');
		$this->addOption('parameters', null, InputOption::VALUE_REQUIRED, '[Internal]');
		$this->addOption('events', null, InputOption::VALUE_NONE, '[Internal] Emit framework events on stdout');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$json = $input->getOption('json');
		$params = $input->getOption('parameters');
		$events = $input->getOption('events');

		[$onStarted, $onFinished] = $events
			? $this->createEventEmitters()
			: [null, null];

		ob_start(static fn () => null);
		try {
			$summary = $this->scheduler->runJob(
				$input->getArgument('id'),
				!$input->getOption('no-force'),
				$params === null
					? null
					: RunParameters::fromArray(json_decode($params, true, 512, JSON_THROW_ON_ERROR)),
				$onStarted,
				$onFinished,
			);

			$stdout = ($tmp = ob_get_clean()) === false ? '' : $tmp;
		} catch (JobFailure $e) {
			ob_end_clean();

			// In --events mode, the subprocess already emitted the `finished` event via the
			// afterJobEmitter inside runInternal. Emit a `failure` event so the parent knows
			// the job's throwable was not handled by an errorHandler and can propagate a
			// RunFailure — then exit cleanly so the parent treats the subprocess as succeeded
			// at reporting (rather than a crashed subprocess).
			if ($events) {
				$previous = $e->getPrevious();
				self::emitEvent([
					'type' => SubprocessEventProtocol::TypeFailure,
					'class' => get_class($previous),
					'message' => $previous->getMessage(),
				]);

				return $this->exitCodeFromState($e->getSummary());
			}

			throw $e;
		} catch (Throwable $e) {
			ob_end_clean();

			throw $e;
		}

		if ($events) {
			// `finished` event already emitted via emitter — no additional stdout needed.
			return $summary === null ? self::SUCCESS : $this->exitCodeFromState($summary);
		}

		if ($summary === null) {
			if ($json) {
				$output->writeln(json_encode(null, JSON_THROW_ON_ERROR));
			} else {
				$output->writeln('<info>Command was not executed because it is not its due time</info>');
			}

			return self::SUCCESS;
		}

		if ($json) {
			$this->renderJobAsJson($summary, $stdout, $output);
		} else {
			if ($stdout !== '') {
				$output->writeln($stdout);
			}

			$this->renderJob($summary, $this->getTerminalWidth(), $output);
		}

		return $this->exitCodeFromState($summary);
	}

	/**
	 * @return array{0: Closure(JobInfo): void, 1: Closure(JobInfo, JobResult): void}
	 */
	private function createEventEmitters(): array
	{
		$onStarted = static function (JobInfo $info): void {
			self::emitEvent([
				'type' => SubprocessEventProtocol::TypeStarted,
				'info' => $info->toArray(),
			]);
		};

		$onFinished = static function (JobInfo $info, JobResult $result): void {
			$captured = ob_get_contents();
			self::emitEvent([
				'type' => SubprocessEventProtocol::TypeFinished,
				'info' => $info->toArray(),
				'result' => $result->toArray(),
				'stdout' => $captured === false ? '' : $captured,
			]);
		};

		return [$onStarted, $onFinished];
	}

	/**
	 * @param array<mixed> $payload
	 */
	private static function emitEvent(array $payload): void
	{
		$line = SubprocessEventProtocol::EventMarker
			. json_encode($payload, JSON_THROW_ON_ERROR)
			. "\n";

		fwrite(STDOUT, $line);
		fflush(STDOUT);
	}

	private function exitCodeFromState(JobSummary $summary): int
	{
		return $summary->getResult()->getState() === JobResultState::fail()
			? self::FAILURE
			: self::SUCCESS;
	}

	private function renderJobAsJson(JobSummary $summary, string $stdout, OutputInterface $output): void
	{
		$jobData = $summary->toArray() + ['stdout' => $stdout];

		$output->writeln(
			json_encode($jobData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
		);
	}

}
