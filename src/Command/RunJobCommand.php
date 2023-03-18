<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Orisai\Scheduler\Scheduler;
use Orisai\Scheduler\Status\JobResultState;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RunJobCommand extends BaseRunCommand
{

	private Scheduler $scheduler;

	public function __construct(Scheduler $scheduler)
	{
		parent::__construct();
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
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$summary = $this->scheduler->runJob(
			$input->getArgument('id'),
			!$input->getOption('no-force'),
		);

		if ($summary === null) {
			$output->writeln('<info>Command was not executed because it is not its due time</info>');

			return self::SUCCESS;
		}

		$this->renderJob($summary, $this->getTerminalWidth(), $output);

		return $summary->getResult()->getState() === JobResultState::fail()
			? self::FAILURE
			: self::SUCCESS;
	}

}
