<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Orisai\Clock\SystemClock;
use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Scheduler;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use function abs;
use function array_splice;
use function max;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function str_pad;
use function str_repeat;
use function strnatcmp;
use function uasort;
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

	protected function configure(): void
	{
		/** @infection-ignore-all */
		parent::configure();
		$this->addOption('next', null, InputOption::VALUE_OPTIONAL, 'Sort jobs by their next execution time', false);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$nextOption = $this->validateOptionNext($input);

		$jobs = $this->scheduler->getScheduledJobs();

		if ($jobs === []) {
			$output->writeln('<info>No scheduled jobs have been defined.</info>');

			return self::SUCCESS;
		}

		$terminalWidth = $this->getTerminalWidth();
		$expressionSpacing = $this->getCronExpressionSpacing($jobs);

		foreach ($this->sortJobs($jobs, $nextOption) as $key => [$job, $expression]) {
			$expressionString = $this->formatCronExpression($expression, $expressionSpacing);

			$name = $job->getName();

			$nextDueDateLabel = 'Next Due:';
			$nextDueDate = $this->getNextDueDateForEvent($expression);
			$nextDueDate = $output->isVerbose()
				? $nextDueDate->format('Y-m-d H:i:s P')
				: $this->getRelativeTime($nextDueDate);

			$dots = str_repeat(
				'.',
				max(
				/* @infection-ignore-all */
					$terminalWidth - mb_strlen($expressionString . $key . $name . $nextDueDateLabel . $nextDueDate) - 9,
					0,
				),
			);

			$output->writeln(sprintf(
				'  <fg=yellow>%s</>  [%s] %s<fg=#6C7280>%s %s %s</>',
				$expressionString,
				$key,
				$name,
				$dots,
				$nextDueDateLabel,
				$nextDueDate,
			));
		}

		return self::SUCCESS;
	}

	/**
	 * @return bool|int<1, max>
	 */
	private function validateOptionNext(InputInterface $input)
	{
		$next = $input->getOption('next');

		if ($next === false) {
			return false;
		}

		if ($next === null) {
			return true;
		}

		if (
			/** @infection-ignore-all */
			preg_match('#^[+-]?[0-9]+$#D', $next) !== 1
			|| ($nextInt = (int) $next) <= 0
		) {
			throw InvalidArgument::create()
				->withMessage(
					"Command '{$this->getName()}' option --next expects an int value larger than 0, '$next' given.",
				);
		}

		return $nextInt;
	}

	/**
	 * @param array<int|string, array{Job, CronExpression}> $jobs
	 * @param bool|int<1, max>                              $next
	 * @return array<int|string, array{Job, CronExpression}>
	 */
	private function sortJobs(array $jobs, $next): array
	{
		if ($next !== false) {
			/** @infection-ignore-all */
			uasort($jobs, function ($a, $b): int {
				$nextDueDateA = $this->getNextDueDateForEvent($a[1])
					->setTimezone(new DateTimeZone('UTC'));
				$nextDueDateB = $this->getNextDueDateForEvent($b[1])
					->setTimezone(new DateTimeZone('UTC'));

				if (
					$nextDueDateA->format(DateTimeInterface::ATOM)
					=== $nextDueDateB->format(DateTimeInterface::ATOM)
				) {
					return 0;
				}

				return $nextDueDateA < $nextDueDateB ? -1 : 1;
			});

			if ($next !== true) {
				array_splice($jobs, $next);
			}
		} else {
			/** @infection-ignore-all */
			uasort($jobs, static function ($a, $b): int {
				$nameA = $a[0]->getName();
				$nameB = $b[0]->getName();

				if ($nameA === $nameB) {
					return 0;
				}

				return strnatcmp($nameA, $nameB);
			});
		}

		return $jobs;
	}

	private function getNextDueDateForEvent(CronExpression $expression): DateTimeImmutable
	{
		return DateTimeImmutable::createFromMutable(
			$expression->getNextRunDate($this->clock->now()),
		);
	}

	/**
	 * @infection-ignore-all
	 */
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
	 * @param array<int|string, array{Job, CronExpression}> $jobs
	 *
	 * @infection-ignore-all
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
