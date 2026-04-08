<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Generator;
use Orisai\Clock\SystemClock;
use Orisai\Scheduler\Maintenance\MaintenanceManager;
use Orisai\Scheduler\Scheduler;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunSummary;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function function_exists;
use function json_encode;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_signal_get_handler;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const SIGINT;
use const SIGTERM;

final class RunCommand extends BaseRunCommand
{

	private const ExitMaintenance = 2;

	private Scheduler $scheduler;

	private ?MaintenanceManager $maintenanceManager;

	public function __construct(
		Scheduler $scheduler,
		?ClockInterface $clock = null,
		?MaintenanceManager $maintenanceManager = null
	)
	{
		parent::__construct($clock ?? new SystemClock());
		$this->scheduler = $scheduler;
		$this->maintenanceManager = $maintenanceManager;
	}

	public static function getDefaultName(): string
	{
		return 'scheduler:run';
	}

	public static function getDefaultDescription(): string
	{
		return 'Run scheduler once';
	}

	protected function configure(): void
	{
		$this->addOption('json', null, InputOption::VALUE_NONE, 'Output in json format');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$previousHandlers = $this->registerSignalHandlers($output);

		try {
			$generator = $this->scheduler->runPromise();

			$success = $input->getOption('json')
				? $this->renderJobsAsJson($output, $generator)
				: $this->renderJobs($output, $generator);

			$runSummary = $generator->getReturn();
			if ($runSummary->isMaintenanceActive()) {
				return self::ExitMaintenance;
			}

			return $success ? self::SUCCESS : self::FAILURE;
		} finally {
			$this->restoreSignalHandlers($previousHandlers);
		}
	}

	/**
	 * @return array{(callable(): mixed)|int, (callable(): mixed)|int}|null
	 */
	private function registerSignalHandlers(OutputInterface $output): ?array
	{
		if ($this->maintenanceManager === null) {
			return null;
		}

		if (!function_exists('pcntl_async_signals')) {
			return null;
		}

		pcntl_async_signals(true);

		$previousTermHandler = pcntl_signal_get_handler(SIGTERM);
		$previousIntHandler = pcntl_signal_get_handler(SIGINT);

		$maintenanceManager = $this->maintenanceManager;
		$shutdownRequested = false;
		$handler = static function () use ($maintenanceManager, &$shutdownRequested, $output): void {
			// @codeCoverageIgnoreStart
			// Tested via real subprocess in SignalHandlingTest, but coverage is not collected from subprocesses
			if ($shutdownRequested) {
				$output->writeln('<error>Forcing immediate shutdown.</error>');
				exit(1);
			}

			// @codeCoverageIgnoreEnd
			$shutdownRequested = true;
			$maintenanceManager->requestShutdown();
			$output->writeln('<comment>Shutdown requested, stopping jobs gracefully...</comment>');
		};

		pcntl_signal(SIGTERM, $handler);
		pcntl_signal(SIGINT, $handler);

		return [$previousTermHandler, $previousIntHandler];
	}

	/**
	 * @param array{(callable(): mixed)|int, (callable(): mixed)|int}|null $previousHandlers
	 */
	private function restoreSignalHandlers(?array $previousHandlers): void
	{
		if ($previousHandlers === null) {
			return; // @codeCoverageIgnore
		}

		pcntl_signal(SIGTERM, $previousHandlers[0]);
		pcntl_signal(SIGINT, $previousHandlers[1]);
	}

	/**
	 * @param Generator<int, JobSummary, void, RunSummary> $generator
	 */
	private function renderJobsAsJson(OutputInterface $output, Generator $generator): bool
	{
		$summaries = [];
		$success = true;
		foreach ($generator as $jobSummary) {
			if ($success && $jobSummary->getResult()->getState() === JobResultState::fail()) {
				$success = false;
			}

			$summaries[] = $jobSummary->toArray();
		}

		$output->writeln(json_encode($summaries, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

		return $success;
	}

	/**
	 * @param Generator<int, JobSummary, void, RunSummary> $generator
	 */
	private function renderJobs(OutputInterface $output, Generator $generator): bool
	{
		$terminalWidth = $this->getTerminalWidth();

		$success = true;
		foreach ($generator as $jobSummary) {
			if ($success && $jobSummary->getResult()->getState() === JobResultState::fail()) {
				$success = false;
			}

			$this->renderJob($jobSummary, $terminalWidth, $output);
		}

		return $success;
	}

}
