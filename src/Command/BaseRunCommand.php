<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use function assert;
use function max;
use function mb_strlen;
use function number_format;
use function sprintf;
use function str_repeat;
use function strip_tags;
use function strtoupper;

abstract class BaseRunCommand extends Command
{

	private ClockInterface $clock;

	public function __construct(ClockInterface $clock)
	{
		parent::__construct();
		$this->clock = $clock;
	}

	protected function getTerminalWidth(): int
	{
		return (new Terminal())->getWidth();
	}

	protected function renderJob(JobSummary $summary, int $terminalWidth, OutputInterface $output): void
	{
		$info = $summary->getInfo();
		$result = $summary->getResult();

		$timezone = $this->clock->now()->getTimezone();
		$runStart = $info->getStart()->setTimezone($timezone)->format('Y-m-d H:i:s');
		$running = ' Running ';
		$id = $info->getId();
		$name = $info->getName();
		/* @infection-ignore-all */
		$diff = (int) $result->getEnd()->format('Uv') - (int) $info->getStart()->format('Uv');
		$runTime = number_format($diff) . 'ms';

		$state = $result->getState();
		$stateName = strtoupper($state->value);
		switch ($state) {
			case JobResultState::done():
				$status = "<fg=#16a34a>$stateName</>";

				break;
			case JobResultState::fail():
				$status = "<fg=#ef4444>$stateName</>";

				break;
			case JobResultState::lock():
				$status = "<fg=#ca8a04>$stateName</>";

				break;
		}

		assert(isset($status));

		$dots = str_repeat(
			'.',
			max(
			/* @infection-ignore-all */
				$terminalWidth - mb_strlen($runStart . $running . $id . $name . $runTime . strip_tags($status)) - 5,
				0,
			),
		);

		$output->writeln(sprintf(
			'<fg=gray>%s</>%s[%s] %s<fg=#6C7280>%s</> <fg=gray>%s</> %s',
			$runStart,
			$running,
			$id,
			$name,
			$dots,
			$runTime,
			$status,
		));
	}

}
