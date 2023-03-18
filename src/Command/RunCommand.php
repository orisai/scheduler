<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Orisai\Scheduler\Scheduler;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\RunSummary;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$summary = $this->scheduler->run();

		$this->renderJobs($output, $summary);

		return $this->getExitCode($summary);
	}

	private function renderJobs(OutputInterface $output, RunSummary $summary): void
	{
		$terminalWidth = $this->getTerminalWidth();

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
