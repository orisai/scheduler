<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use DateTimeZone;
use Orisai\Clock\SystemClock;
use Orisai\CronExpressionExplainer\CronExpressionExplainer;
use Orisai\CronExpressionExplainer\DefaultCronExpressionExplainer;
use Orisai\Scheduler\Job\JobSchedule;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use function array_key_last;

/**
 * @internal
 */
abstract class BaseExplainCommand extends Command
{

	protected CronExpressionExplainer $explainer;

	protected ClockInterface $clock;

	public function __construct(?CronExpressionExplainer $explainer, ?ClockInterface $clock)
	{
		$this->explainer = $explainer ?? new DefaultCronExpressionExplainer();
		$this->clock = $clock ?? new SystemClock();
		parent::__construct();
	}

	protected function getSupportedLanguages(): string
	{
		$string = '';
		$languages = $this->explainer->getSupportedLanguages();
		$last = array_key_last($languages);
		foreach ($languages as $code => $name) {
			$string .= "$code ($name)";
			if ($code !== $last) {
				$string .= ', ';
			}
		}

		return $string;
	}

	protected function computeTimeZone(JobSchedule $jobSchedule, DateTimeZone $renderedTimeZone): ?DateTimeZone
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

}
