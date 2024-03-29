<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use DateTimeZone;
use Orisai\CronExpressionExplainer\CronExpressionExplainer;
use Orisai\CronExpressionExplainer\Exception\UnsupportedExpression;
use Orisai\Scheduler\Scheduler;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function array_key_exists;
use function assert;
use function in_array;
use function is_string;
use function preg_match;
use function timezone_identifiers_list;

final class ExplainCommand extends BaseExplainCommand
{

	private Scheduler $scheduler;

	public function __construct(
		Scheduler $scheduler,
		?CronExpressionExplainer $explainer = null,
		?ClockInterface $clock = null
	)
	{
		parent::__construct($explainer, $clock);
		$this->scheduler = $scheduler;
	}

	public static function getDefaultName(): string
	{
		return 'scheduler:explain';
	}

	public static function getDefaultDescription(): string
	{
		return 'Explain cron expression';
	}

	protected function configure(): void
	{
		/** @infection-ignore-all */
		parent::configure();
		$this->addOption('id', null, InputOption::VALUE_REQUIRED, 'ID of job to explain');
		$this->addOption('expression', 'e', InputOption::VALUE_REQUIRED, 'Expression to explain');
		$this->addOption('seconds', 's', InputOption::VALUE_REQUIRED, 'Repeat every n seconds');
		$this->addOption('timezone', 'tz', InputOption::VALUE_REQUIRED, 'The timezone time should be displayed in');
		$this->addOption(
			'language',
			'l',
			InputOption::VALUE_REQUIRED,
			"Translate expression in given language - {$this->getSupportedLanguages()}",
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$options = $this->validateOptions($input, $output);
		if ($options === null) {
			return self::FAILURE;
		}

		$id = $options['id'];
		$expression = $options['expression'];
		$seconds = $options['seconds'];
		$timezone = $options['timezone'];
		$language = $options['language'];

		if ($id !== null) {
			return $this->explainJobWithId($id, $output);
		}

		if ($expression !== null) {
			return $this->explainExpression($expression, $seconds, $timezone, $language, $output);
		}

		return $this->explainSyntax($output);
	}

	/**
	 * @return array{id: string|null, expression: string|null, seconds: int<0, 59>|null, timezone: DateTimeZone|null, language: string|null}|null
	 */
	private function validateOptions(InputInterface $input, OutputInterface $output): ?array
	{
		$hasErrors = false;

		$id = $input->getOption('id');
		assert(is_string($id) || $id === null);

		$expression = $input->getOption('expression');
		assert(is_string($expression) || $expression === null);

		if ($id !== null && $expression !== null) {
			$hasErrors = true;
			$output->writeln('<error>Options --id and --expression cannot be combined.</error>');
		}

		$seconds = $input->getOption('seconds');
		assert(is_string($seconds) || $seconds === null);
		if ($seconds !== null) {
			if (
				/** @infection-ignore-all */
				preg_match('#^[+-]?[0-9]+$#D', $seconds) !== 1
				|| ($seconds = (int) $seconds) < 0
				|| $seconds > 59
			) {
				$hasErrors = true;
				$output->writeln("<error>Option --seconds expects an int<0, 59>, '$seconds' given.</error>");
			}

			if ($expression === null) {
				$hasErrors = true;
				$output->writeln('<error>Option --seconds must be used with --expression.</error>');
			}
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

			if ($expression === null) {
				$hasErrors = true;
				$output->writeln('<error>Option --timezone must be used with --expression.</error>');
			}
		}

		$language = $input->getOption('language');
		assert($language === null || is_string($language));
		if ($language !== null) {
			if (!array_key_exists($language, $this->explainer->getSupportedLanguages())) {
				$hasErrors = true;
				$output->writeln(
					"<error>Option --language expects no value or one of supported languages, '$language' given."
					. ' Use --help to list available languages.</error>',
				);
			}

			if ($expression === null) {
				$hasErrors = true;
				$output->writeln('<error>Option --language must be used with --expression.</error>');
			}
		}

		if ($hasErrors) {
			return null;
		}

		// Happens only when $hasErrors = true
		assert(!is_string($seconds) && $seconds >= 0 && $seconds <= 59);
		assert(!is_string($timezone));

		return [
			'id' => $id,
			'expression' => $expression,
			'seconds' => $seconds,
			'timezone' => $timezone,
			'language' => $language,
		];
	}

	/**
	 * @param int<0, 59>|null $seconds
	 */
	private function explainExpression(
		string $expression,
		?int $seconds,
		?DateTimeZone $timeZone,
		?string $language,
		OutputInterface $output
	): int
	{
		try {
			$output->writeln($this->explainer->explain(
				$expression,
				$seconds,
				$timeZone,
				$language,
			));
		} catch (UnsupportedExpression $exception) {
			$output->writeln("<error>{$exception->getMessage()}</error>");

			return self::FAILURE;
		}

		return self::SUCCESS;
	}

	private function explainJobWithId(string $id, OutputInterface $output): int
	{
		$jobSchedules = $this->scheduler->getJobSchedules();
		$jobSchedule = $jobSchedules[$id] ?? null;

		if ($jobSchedule === null) {
			$output->writeln("<error>Job with id '$id' does not exist.</error>");

			return self::FAILURE;
		}

		$output->writeln($this->explainer->explain(
			$jobSchedule->getExpression()->getExpression(),
			$jobSchedule->getRepeatAfterSeconds(),
			$this->computeTimeZone($jobSchedule, $this->clock->now()->getTimezone()),
		));

		return self::SUCCESS;
	}

	private function explainSyntax(OutputInterface $output): int
	{
		$output->writeln(
			<<<'CMD'
<fg=yellow>*   *   *   *   *</>
-   -   -   -   -
|   |   |   |   |
|   |   |   |   |
|   |   |   |   +----- day of week (<fg=yellow>0-7</>) (Sunday = <fg=yellow>0</> or <fg=yellow>7</>) (or <fg=yellow>SUN-SAT</>)
|   |   |   +--------- month (<fg=yellow>1-12</>) (or <fg=yellow>JAN-DEC</>)
|   |   +------------- day of month (<fg=yellow>1-31</>)
|   +----------------- hour (<fg=yellow>0-23</>)
+--------------------- minute (<fg=yellow>0-59</>)

Each part of expression can also use wildcard, lists, ranges and steps:

- wildcard - match always
  - e.g. <fg=yellow>* * * * *</> - At every minute.
- lists - match list of values, ranges and steps
  - e.g. <fg=yellow>15,30 * * * *</> - At minute 15 and 30.
- ranges - match values in range
  - e.g. <fg=yellow>1-9 * * * *</> - At every minute from 1 through 9.
- steps - match every nth value in range
  - e.g. <fg=yellow>*/5 * * * *</> - At every 5th minute.
  - e.g. <fg=yellow>0-30/5 * * * *</> - At every 5th minute from 0 through 30.
- combinations
  - e.g. <fg=yellow>0-14,30-44 * * * *</> - At every minute from 0 through 14 and every minute from 30 through 44.

You can also use macro instead of an expression:

- <fg=yellow>@yearly</>, <fg=yellow>@annually</> - Run once a year, midnight, Jan. 1 (same as <fg=yellow>0 0 1 1 *</>)
- <fg=yellow>@monthly</> - Run once a month, midnight, first of month (same as <fg=yellow>0 0 1 * *</>)
- <fg=yellow>@weekly</> - Run once a week, midnight on Sun (same as <fg=yellow>0 0 * * 0</>)
- <fg=yellow>@daily</>, <fg=yellow>@midnight</> - Run once a day, midnight (same as <fg=yellow>0 0 * * *</>)
- <fg=yellow>@hourly</> - Run once an hour, first minute (same as <fg=yellow>0 * * * *</>)

Although they are not part of cron expression syntax, you can also add to job:

- seconds - repeat job every n seconds
- timezone - run only when cron expression matches within given timezone
CMD,
		);

		return self::SUCCESS;
	}

}
