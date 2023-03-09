<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Orisai\Scheduler\Scheduler;
use Orisai\Scheduler\Status\RunSummary;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use function max;
use function mb_strlen;
use function number_format;
use function sprintf;
use function str_repeat;
use function strip_tags;

final class RunCommand extends Command
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
		$jobs = $summary->getJobs();
		$terminalWidth = $this->getTerminalWidth();

		foreach ($jobs as [$info, $result]) {
			$runStart = $info->getStart()->format('Y-m-d H:i:s');
			$running = ' Running ';
			$jobName = $info->getName();
			$diff = (int) $result->getEnd()->format('Uv') - (int) $info->getStart()->format('Uv');
			$runTime = number_format($diff) . 'ms';
			$status = $result->getThrowable() === null ? '<fg=#16A34A>DONE</>' : '<fg=#EF4444>FAIL</>';

			$dots = str_repeat(
				'.',
				max(
					$terminalWidth - mb_strlen($runStart . $running . $jobName . $runTime . strip_tags($status)) - 2,
					0,
				),
			);

			$output->writeln(sprintf(
				'<fg=gray>%s</>%s%s<fg=#6C7280>%s</> <fg=gray>%s</> %s',
				$runStart,
				$running,
				$jobName,
				$dots,
				$runTime,
				$status,
			));
		}
	}

	private function getExitCode(RunSummary $summary): int
	{
		foreach ($summary->getJobs() as [$info, $result]) {
			if ($result->getThrowable() !== null) {
				return self::FAILURE;
			}
		}

		return self::SUCCESS;
	}

	private function getTerminalWidth(): int
	{
		return (new Terminal())->getWidth();
	}

}
