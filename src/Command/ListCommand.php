<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Orisai\CronExpressionExplainer\CronExpressionExplainer;
use Orisai\CronExpressionExplainer\DefaultCronExpressionExplainer;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Scheduler;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use function abs;
use function assert;
use function floor;
use function in_array;
use function is_bool;
use function is_int;
use function is_string;
use function max;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function str_pad;
use function str_repeat;
use function strlen;
use function strnatcmp;
use function timezone_identifiers_list;
use function uasort;
use const STR_PAD_LEFT;

final class ListCommand extends BaseExplainCommand
{

	private Scheduler $scheduler;

	private CronExpressionExplainer $explainer;

	public function __construct(
		Scheduler $scheduler,
		?ClockInterface $clock = null,
		?CronExpressionExplainer $explainer = null
	)
	{
		parent::__construct($clock);
		$this->scheduler = $scheduler;
		$this->explainer = $explainer ?? new DefaultCronExpressionExplainer();
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
		$this->addOption('timezone', 'tz', InputOption::VALUE_REQUIRED, 'The timezone times should be displayed in');
		$this->addOption('explain', null, InputOption::VALUE_NONE, 'Explain expression');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$options = $this->validateOptions($input, $output);
		if ($options === null) {
			return self::FAILURE;
		}

		$nextOption = $options['next'];
		$timeZone = $options['timezone'];
		$explain = $options['explain'];

		$jobSchedules = $this->scheduler->getJobSchedules();

		if ($jobSchedules === []) {
			$output->writeln('<info>No scheduled jobs have been defined.</info>');

			return self::SUCCESS;
		}

		$terminalWidth = $this->getTerminalWidth();
		$expressionSpacing = $this->getCronExpressionSpacing($jobSchedules);
		$repeatAfterSecondsSpacing = $this->getRepeatAfterSecondsSpacing($jobSchedules);
		$timeZoneSpacing = $this->getTimeZoneSpacing($jobSchedules, $timeZone);

		foreach ($this->sortJobs($jobSchedules, $nextOption, $timeZone) as $key => $jobSchedule) {
			$expressionString = $this->formatCronExpression($jobSchedule->getExpression(), $expressionSpacing);
			$repeatAfterSecondsString = $this->formatRepeatAfterSeconds(
				$jobSchedule->getRepeatAfterSeconds(),
				$repeatAfterSecondsSpacing,
			);
			$computedTimeZone = $this->computeTimeZone($jobSchedule, $timeZone);
			$timeZoneString = $this->formatTimeZone($computedTimeZone, $timeZoneSpacing);

			$name = $jobSchedule->getJob()->getName();

			$nextDueDateLabel = 'Next Due:';
			$nextDueDate = $this->getNextDueDate($jobSchedule, $timeZone);
			$nextDueDate = $output->isVerbose()
				? $nextDueDate->format('Y-m-d H:i:s P')
				: $this->getRelativeTime($nextDueDate);

			$dots = str_repeat(
				'.',
				max(
				/* @infection-ignore-all */
					$terminalWidth - mb_strlen(
						$expressionString . $repeatAfterSecondsString . $timeZoneString . $key . $name . $nextDueDateLabel . $nextDueDate,
					) - 9,
					0,
				),
			);

			$output->writeln(sprintf(
				'  <fg=yellow>%s</><fg=#6C7280>%s</>%s  [%s] %s<fg=#6C7280>%s %s %s</>',
				$expressionString,
				$repeatAfterSecondsString,
				$timeZoneString,
				$key,
				$name,
				$dots,
				$nextDueDateLabel,
				$nextDueDate,
			));

			if ($explain) {
				$output->writeln($this->explainer->explain(
					$jobSchedule->getExpression()->getExpression(),
					$jobSchedule->getRepeatAfterSeconds(),
					$computedTimeZone,
				));
			}
		}

		return self::SUCCESS;
	}

	/**
	 * @return array{next: int<1, max>|bool, timezone: DateTimeZone, explain: bool}|null
	 */
	private function validateOptions(InputInterface $input, OutputInterface $output): ?array
	{
		$hasErrors = false;

		$next = $input->getOption('next');
		assert(is_string($next) || $next === false || $next === null);
		if ($next === null) {
			$next = true;
		} elseif (
			/** @infection-ignore-all */
			is_string($next)
			&&
			(
				preg_match('#^[+-]?[0-9]+$#D', $next) !== 1
				|| ($next = (int) $next) < 1
			)
		) {
			$hasErrors = true;
			$output->writeln("<error>Option --next expects an int<1, max>, '$next' given.</error>");
		}

		$timezone = $input->getOption('timezone');
		assert(is_string($timezone) || $timezone === null);
		if ($timezone !== null) {
			if (!in_array($timezone, timezone_identifiers_list(), true)) {
				$hasErrors = true;
				$output->writeln("<error>Option --timezone expects a valid timezone, '$timezone' given.</error>");
			} else {
				$timezone = new DateTimeZone($timezone);
			}
		} else {
			$timezone = $this->clock->now()->getTimezone();
		}

		$explain = $input->getOption('explain');
		assert(is_bool($explain));

		if ($hasErrors) {
			return null;
		}

		// Happens only when $hasErrors = true
		assert(is_bool($next) || (is_int($next) && $next >= 1));
		assert(!is_string($timezone));

		return [
			'next' => $next,
			'timezone' => $timezone,
			'explain' => $explain,
		];
	}

	/**
	 * @param array<int|string, JobSchedule> $jobSchedules
	 * @param bool|int<1, max>               $next
	 * @return array<int|string, JobSchedule>
	 */
	private function sortJobs(array $jobSchedules, $next, DateTimeZone $timeZone): array
	{
		if ($next !== false) {
			/** @infection-ignore-all */
			uasort($jobSchedules, function (JobSchedule $a, JobSchedule $b) use ($timeZone): int {
				$nextDueDateA = $this->getNextDueDate($a, $timeZone)
					->setTimezone(new DateTimeZone('UTC'));
				$nextDueDateB = $this->getNextDueDate($b, $timeZone)
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
				$slicedJobs = [];
				$count = 0;
				foreach ($jobSchedules as $key => $value) {
					if ($count >= $next) {
						break;
					}

					$slicedJobs[$key] = $value;
					$count++;
				}

				$jobSchedules = $slicedJobs;
			}
		} else {
			/** @infection-ignore-all */
			uasort($jobSchedules, static function (JobSchedule $a, JobSchedule $b): int {
				$nameA = $a->getJob()->getName();
				$nameB = $b->getJob()->getName();

				if ($nameA === $nameB) {
					return 0;
				}

				return strnatcmp($nameA, $nameB);
			});
		}

		return $jobSchedules;
	}

	private function getNextDueDate(JobSchedule $jobSchedule, DateTimeZone $timeZone): DateTimeImmutable
	{
		$expression = $jobSchedule->getExpression();
		$repeatAfterSeconds = $jobSchedule->getRepeatAfterSeconds();

		$now = $this->clock->now()->setTimezone($timeZone);
		$nextDueDate = DateTimeImmutable::createFromMutable(
			$expression->getNextRunDate($now)->setTimezone($timeZone),
		);

		if ($repeatAfterSeconds === 0) {
			return $nextDueDate;
		}

		$previousDueDate = DateTimeImmutable::createFromMutable(
			$expression->getPreviousRunDate($now, 0, true)->setTimezone($timeZone),
		);

		if (!$this->wasPreviousDueDateInCurrentMinute($now, $previousDueDate)) {
			return $nextDueDate;
		}

		$currentSecond = (int) $now->format('s');
		$runTimes = (int) floor($currentSecond / $repeatAfterSeconds);
		$nextRunSecond = ($runTimes + 1) * $repeatAfterSeconds;

		// Don't abuse seconds overlap
		if ($nextRunSecond > 59) {
			return $nextDueDate;
		}

		return $now->setTime(
			(int) $now->format('H'),
			(int) $now->format('i'),
			$nextRunSecond,
		);
	}

	private function wasPreviousDueDateInCurrentMinute(DateTimeImmutable $now, DateTimeImmutable $previousDueDate): bool
	{
		$currentMinute = $now->setTime(
			(int) $now->format('H'),
			(int) $now->format('i'),
		);

		return $currentMinute->getTimestamp() === $previousDueDate->getTimestamp();
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
	 * @param array<int|string, JobSchedule> $jobSchedules
	 *
	 * @infection-ignore-all
	 */
	private function getCronExpressionSpacing(array $jobSchedules): int
	{
		$max = 0;
		foreach ($jobSchedules as $jobSchedule) {
			$length = mb_strlen($jobSchedule->getExpression()->getExpression());
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

	/**
	 * @param array<int|string, JobSchedule> $jobSchedules
	 *
	 * @infection-ignore-all
	 */
	private function getRepeatAfterSecondsSpacing(array $jobSchedules): int
	{
		$max = 0;
		foreach ($jobSchedules as $jobSchedule) {
			$repeatAfterSeconds = $jobSchedule->getRepeatAfterSeconds();
			if ($repeatAfterSeconds === 0) {
				continue;
			}

			$length = strlen((string) $repeatAfterSeconds);
			if ($length > $max) {
				$max = $length;
			}
		}

		if ($max !== 0) {
			$max += 3;
		}

		return $max;
	}

	private function formatRepeatAfterSeconds(int $repeatAfterSeconds, int $spacing): string
	{
		if ($repeatAfterSeconds === 0) {
			return str_pad('', $spacing);
		}

		return str_pad(" / $repeatAfterSeconds", $spacing);
	}

	/**
	 * @param array<int|string, JobSchedule> $jobSchedules
	 *
	 * @infection-ignore-all
	 */
	private function getTimeZoneSpacing(array $jobSchedules, DateTimeZone $renderedTimeZone): int
	{
		$max = 0;
		foreach ($jobSchedules as $jobSchedule) {
			$timeZone = $this->computeTimeZone($jobSchedule, $renderedTimeZone);

			if ($timeZone === null) {
				continue;
			}

			$length = strlen($timeZone->getName());
			if ($length > $max) {
				$max = $length;
			}
		}

		if ($max !== 0) {
			$max += 3;
		}

		return $max;
	}

	private function formatTimeZone(?DateTimeZone $timeZone, int $spacing): string
	{
		if ($timeZone === null) {
			return str_pad('', $spacing);
		}

		return str_pad(" ({$timeZone->getName()})", $spacing);
	}

	private function getTerminalWidth(): int
	{
		return (new Terminal())->getWidth();
	}

}
