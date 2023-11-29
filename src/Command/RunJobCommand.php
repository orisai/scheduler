<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Orisai\Scheduler\Scheduler;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunParameters;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function json_decode;
use function json_encode;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

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
		$this->addOption('json', null, InputOption::VALUE_NONE, 'Output in json format');
		$this->addOption('parameters', null, InputOption::VALUE_REQUIRED, '[Internal]');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$json = $input->getOption('json');
		$params = $input->getOption('parameters');
		$summary = $this->scheduler->runJob(
			$input->getArgument('id'),
			!$input->getOption('no-force'),
			$params === null
				? null
				: RunParameters::fromArray(json_decode($params, true, 512, JSON_THROW_ON_ERROR)),
		);

		if ($summary === null) {
			if ($json) {
				$output->writeln(json_encode(null, JSON_THROW_ON_ERROR));
			} else {
				$output->writeln('<info>Command was not executed because it is not its due time</info>');
			}

			return self::SUCCESS;
		}

		if ($json) {
			$this->renderJobAsJson($summary, $output);
		} else {
			$this->renderJob($summary, $this->getTerminalWidth(), $output);
		}

		return $summary->getResult()->getState() === JobResultState::fail()
			? self::FAILURE
			: self::SUCCESS;
	}

	private function renderJobAsJson(JobSummary $summary, OutputInterface $output): void
	{
		$output->writeln(
			json_encode($this->jobToArray($summary), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
		);
	}

}
