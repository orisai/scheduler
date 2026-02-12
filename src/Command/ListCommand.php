<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Orisai\CronExpressionExplainer\CronExpressionExplainer;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Scheduler;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use Throwable;
use function abs;
use function array_key_exists;
use function assert;
use function floor;
use function in_array;
use function is_bool;
use function is_int;
use function is_string;
use function ksort;
use function max;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function str_repeat;
use function timezone_identifiers_list;
use function uasort;
use const SORT_NATURAL;

final class ListCommand extends BaseExplainCommand
{

	private Scheduler $scheduler;

	public function __construct(
		Scheduler $scheduler,
		?ClockInterface $clock = null,
		?CronExpressionExplainer $explainer = null
	)
	{
		parent::__construct($explainer, $clock);
		$this->scheduler = $scheduler;
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
		$this->addOption(
			'explain',
			null,
			InputOption::VALUE_OPTIONAL,
			"Explain expression - {$this->getSupportedLocales()}",
			false,
		);
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

		$maxExpressionLength = 0;
		$data = [];
		foreach ($jobSchedules as $id => $jobSchedule) {
			$expressionString = (string) $jobSchedule->getExpression();

			$seconds = $jobSchedule->getRepeatAfterSeconds();
			$secondsString = $seconds !== 0 ? " / $seconds" : '';

			$computedTimeZone = $this->computeTimeZone($jobSchedule, $timeZone);
			$timeZoneString = $computedTimeZone !== null ? " ({$computedTimeZone->getName()})" : '';

			$expressionLength = mb_strlen($expressionString . $secondsString . $timeZoneString);

			$data[$id] = [
				$expressionString,
				$secondsString,
				$timeZoneString,
				$expressionLength,
				$computedTimeZone,
			];

			if ($expressionLength > $maxExpressionLength) {
				$maxExpressionLength = $expressionLength;
			}
		}

		$terminalWidth = $this->getTerminalWidth();

		foreach ($this->sortJobs($jobSchedules, $nextOption, $timeZone) as $id => $jobSchedule) {
			[$expressionString, $repeatAfterSecondsString, $timeZoneString, $expressionLength, $computedTimeZone] = $data[$id];

			$expressionPadding = str_repeat(' ', $maxExpressionLength - $expressionLength);

			$name = $jobSchedule->getJob()->getName();

			$nextDueDateLabel = 'Next Due:';
			$nextDueDate = $this->getNextDueDate($jobSchedule, $timeZone);

			if ($nextDueDate !== null) {
				$nextDueDateStr = $output->isVerbose()
					? $nextDueDate->format('Y-m-d H:i:s P')
					: $this->getRelativeTime($nextDueDate);
			} else {
				$nextDueDateStr = 'NEVER';
			}

			$dots = str_repeat(
				'.',
				max(
				/* @infection-ignore-all */
					$terminalWidth - mb_strlen(
						$expressionPadding . $id . $name . $nextDueDateLabel . $nextDueDateStr,
					) - $expressionLength - 8,
					0,
				),
			);

			$output->writeln(sprintf(
				'  <fg=yellow>%s</><fg=gray>%s</>%s%s [%s] %s<fg=gray>%s %s %s</>',
				$expressionString,
				$repeatAfterSecondsString,
				$timeZoneString,
				$expressionPadding,
				$id,
				$name,
				$dots,
				$nextDueDateLabel,
				$nextDueDate === null ? "<fg=red>$nextDueDateStr</>" : $nextDueDateStr,
			));

			if ($explain !== false) {
				$explainedExpression = $this->explainer->explain(
					(string) $jobSchedule->getExpression(),
					$jobSchedule->getRepeatAfterSeconds(),
					$computedTimeZone,
					$explain,
				);

				$output->writeln("  <fg=gray>$explainedExpression</>");
				$output->writeln('');
			}
		}

		return self::SUCCESS;
	}

	/**
	 * @return array{next: int<1, max>|bool, timezone: DateTimeZone, explain: false|string|null}|null
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
		assert($explain === null || $explain === false || is_string($explain));
		if (
			is_string($explain)
			&& !array_key_exists($explain, $this->explainer->getSupportedLocales())
		) {
			$hasErrors = true;
			$output->writeln(
				"<error>Option --explain expects no value or one of supported locales, '$explain' given."
				. ' Use --help to list available locales.</error>',
			);
		}

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
				$nextDueDateA = $this->getNextDueDate($a, $timeZone);
				$nextDueDateB = $this->getNextDueDate($b, $timeZone);

				if ($nextDueDateA === null && $nextDueDateB === null) {
					return 0;
				}

				if ($nextDueDateA === null) {
					return -1;
				}

				if ($nextDueDateB === null) {
					return 1;
				}

				$nextDueDateA = $nextDueDateA->setTimezone(new DateTimeZone('UTC'));
				$nextDueDateB = $nextDueDateB->setTimezone(new DateTimeZone('UTC'));

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
			ksort($jobSchedules, SORT_NATURAL);
		}

		return $jobSchedules;
	}

	private function getNextDueDate(JobSchedule $jobSchedule, DateTimeZone $timeZone): ?DateTimeImmutable
	{
		$expression = $jobSchedule->getExpression();
		$repeatAfterSeconds = $jobSchedule->getRepeatAfterSeconds();

		$now = $this->clock->now()->setTimezone($timeZone);
		try {
			$nextDueDate = DateTimeImmutable::createFromMutable(
				$expression->getNextRunDate($now)->setTimezone($timeZone),
			);
		} catch (Throwable $exception) {
			return null;
		}

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

	private function getTerminalWidth(): int
	{
		return (new Terminal())->getWidth();
	}

}
