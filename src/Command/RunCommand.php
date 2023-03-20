<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Orisai\Scheduler\Scheduler;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\RunSummary;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function json_encode;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

final class RunCommand extends BaseRunCommand
{

	private Scheduler $scheduler;

	public function __construct(Scheduler $scheduler)
	{
		parent::__construct();
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
		$summary = $this->scheduler->run();

		$this->renderJobs($input, $output, $summary);

		return $this->getExitCode($summary);
	}

	private function renderJobs(InputInterface $input, OutputInterface $output, RunSummary $summary): void
	{
		$terminalWidth = $this->getTerminalWidth();

		if ($input->getOption('json')) {
			$summaries = [];
			foreach ($summary->getJobs() as $jobSummary) {
				$summaries[] = $this->jobToArray($jobSummary);
			}

			$output->writeln(json_encode($summaries, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

			return;
		}

		foreach ($summary->getJobs() as $jobSummary) {
			$this->renderJob($jobSummary, $terminalWidth, $output);
		}
	}

	private function getExitCode(RunSummary $summary): int
	{
		foreach ($summary->getJobs() as $jobSummary) {
			if ($jobSummary->getResult()->getState() === JobResultState::fail()) {
				return self::FAILURE;
			}
		}

		return self::SUCCESS;
	}

}
