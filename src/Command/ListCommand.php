<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Cron\CronExpression;
use DateTimeImmutable;
use Orisai\Clock\SystemClock;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Scheduler;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use function abs;
use function max;
use function mb_strlen;
use function sprintf;
use function str_pad;
use function str_repeat;
use const STR_PAD_LEFT;

final class ListCommand extends Command
{

	private Scheduler $scheduler;

	private ClockInterface $clock;

	public function __construct(Scheduler $scheduler, ?ClockInterface $clock = null)
	{
		parent::__construct();
		$this->scheduler = $scheduler;
		$this->clock = $clock ?? new SystemClock();
	}

	public static function getDefaultName(): string
	{
		return 'scheduler:list';
	}

	public static function getDefaultDescription(): string
	{
		return 'List all scheduled jobs';
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$jobs = $this->scheduler->getJobs();

		if ($jobs === []) {
			$output->writeln('<info>No scheduled jobs have been defined.</info>');

			return self::SUCCESS;
		}

		$terminalWidth = $this->getTerminalWidth();
		$expressionSpacing = $this->getCronExpressionSpacing($jobs);

		foreach ($jobs as [$job, $expression]) {
			$expressionString = $this->formatCronExpression($expression, $expressionSpacing);

			$command = $job->getName();

			$nextDueDateLabel = 'Next Due:';
			$nextDueDate = $this->getNextDueDateForEvent($expression);
			$nextDueDate = $output->isVerbose()
				? $nextDueDate->format('Y-m-d H:i:s P')
				: $this->getRelativeTime($nextDueDate);

			$dots = str_repeat(
				'.',
				max(
					$terminalWidth - mb_strlen($expressionString . $command . $nextDueDateLabel . $nextDueDate) - 6,
					0,
				),
			);

			$output->writeln(sprintf(
				'  <fg=yellow>%s</>  %s<fg=#6C7280>%s %s %s</>',
				$expressionString,
				$command,
				$dots,
				$nextDueDateLabel,
				$nextDueDate,
			));
		}

		return self::SUCCESS;
	}

	private function getNextDueDateForEvent(CronExpression $expression): DateTimeImmutable
	{
		return DateTimeImmutable::createFromMutable(
			$expression->getNextRunDate($this->clock->now()),
		);
	}

	private function getRelativeTime(DateTimeImmutable $time): string
	{
		$d = [
			0 => [1, 'second'],
			1 => [60, 'minute'],
			2 => [3_600, 'hour'],
			3 => [86_400, 'day'],
			4 => [604_800, 'week'],
			5 => [2_592_000, 'month'],
			6 => [31_104_000, 'year'],
		];

		$w = [];

		$return = '';
		$now = (int) $this->clock->now()->format('U');
		$diff = $now - (int) $time->format('U');
		$secondsLeft = $diff;
		for ($i = 6; $i > -1; $i--) {
			$w[$i] = (int) ($secondsLeft / $d[$i][0]);
			$secondsLeft -= $w[$i] * $d[$i][0];
			if ($w[$i] !== 0) {
				$r = abs($w[$i]);
				$return .= $r . ' ' . $d[$i][1] . ($r > 1 ? 's' : '') . ' ';

				break;
			}
		}

		return $return;
	}

	/**
	 * @param list<array{Job, CronExpression}> $jobs
	 */
	private function getCronExpressionSpacing(array $jobs): int
	{
		$max = 0;
		foreach ($jobs as [$job, $expression]) {
			$length = mb_strlen($expression->getExpression());
			if ($length > $max) {
				$max = $length;
			}
		}

		return $max;
	}

	private function formatCronExpression(CronExpression $expression, int $spacing): string
	{
		return str_pad($expression->getExpression(), $spacing, ' ', STR_PAD_LEFT);
	}

	private function getTerminalWidth(): int
	{
		return (new Terminal())->getWidth();
	}

}
