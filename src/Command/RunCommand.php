<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Generator;
use Orisai\Clock\SystemClock;
use Orisai\Scheduler\Scheduler;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunSummary;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function json_encode;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

final class RunCommand extends BaseRunCommand
{

	private Scheduler $scheduler;

	public function __construct(Scheduler $scheduler, ?ClockInterface $clock = null)
	{
		parent::__construct($clock ?? new SystemClock());
		$this->scheduler = $scheduler;
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
		$summary = $this->scheduler->runPromise();

		$success = $input->getOption('json')
			? $this->renderJobsAsJson($output, $summary)
			: $this->renderJobs($output, $summary);

		return $success ? self::SUCCESS : self::FAILURE;
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
