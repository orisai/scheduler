<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use DateTimeZone;
use Orisai\Clock\SystemClock;
use Orisai\CronExpressionExplainer\CronExpressionExplainer;
use Orisai\CronExpressionExplainer\DefaultCronExpressionExplainer;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Scheduler;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function assert;
use function is_string;

final class ExplainCommand extends Command
{

	private Scheduler $scheduler;

	private CronExpressionExplainer $explainer;

	private ClockInterface $clock;

	public function __construct(
		Scheduler $scheduler,
		?CronExpressionExplainer $explainer = null,
		?ClockInterface $clock = null
	)
	{
		parent::__construct();
		$this->scheduler = $scheduler;
		$this->explainer = $explainer ?? new DefaultCronExpressionExplainer();
		$this->clock = $clock ?? new SystemClock();
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
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$id = $this->validateIdOption($input);

		if ($id !== null) {
			return $this->explainJobWithId($id, $output);
		}

		$this->explainSyntax($output);

		return 0;
	}

	private function validateIdOption(InputInterface $input): ?string
	{
		$id = $input->getOption('id');
		assert(is_string($id) || $id === null);

		return $id;
	}

	private function explainJobWithId(string $id, OutputInterface $output): int
	{
		$jobSchedules = $this->scheduler->getJobSchedules();
		$jobSchedule = $jobSchedules[$id] ?? null;

		if ($jobSchedule === null) {
			$output->writeln("<error>Job with id '$id' does not exist.</error>");

			return 1;
		}

		$output->writeln($this->explainer->explain(
			$jobSchedule->getExpression()->getExpression(),
			$jobSchedule->getRepeatAfterSeconds(),
			$this->computeTimeZone($jobSchedule, $this->clock->now()->getTimezone()),
		));

		return 0;
	}

	private function computeTimeZone(JobSchedule $jobSchedule, DateTimeZone $renderedTimeZone): ?DateTimeZone
	{
		$timeZone = $jobSchedule->getTimeZone();
		$clockTimeZone = $this->clock->now()->getTimezone();

		if ($timeZone === null && $renderedTimeZone->getName() !== $clockTimeZone->getName()) {
			$timeZone = $clockTimeZone;
		}

		if ($timeZone === null) {
			return null;
		}

		if ($timeZone->getName() === $renderedTimeZone->getName()) {
			return null;
		}

		return $timeZone;
	}

	private function explainSyntax(OutputInterface $output): void
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
	}

}
