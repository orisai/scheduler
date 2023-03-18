<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Orisai\Exceptions\Logic\ShouldNotHappen;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use function max;
use function mb_strlen;
use function number_format;
use function sprintf;
use function str_repeat;
use function strip_tags;

abstract class BaseRunCommand extends Command
{

	protected function getTerminalWidth(): int
	{
		return (new Terminal())->getWidth();
	}

	protected function renderJob(JobInfo $info, JobResult $result, int $terminalWidth, OutputInterface $output): void
	{
		$runStart = $info->getStart()->format('Y-m-d H:i:s');
		$running = ' Running ';
		$jobName = $info->getName();
		/* @infection-ignore-all */
		$diff = (int) $result->getEnd()->format('Uv') - (int) $info->getStart()->format('Uv');
		$runTime = number_format($diff) . 'ms';

		switch ($result->getState()) {
			case JobResultState::done():
				$status = '<fg=#16a34a>DONE</>';

				break;
			case JobResultState::fail():
				$status = '<fg=#ef4444>FAIL</>';

				break;
			case JobResultState::skip():
				$status = '<fg=#ca8a04>SKIP</>';

				break;
			default:
				// @codeCoverageIgnoreStart
				/* @infection-ignore-all */
				throw ShouldNotHappen::create();
			// @codeCoverageIgnoreEnd
		}

		$dots = str_repeat(
			'.',
			max(
			/* @infection-ignore-all */
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
