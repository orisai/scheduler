<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use DateTimeZone;
use Orisai\Clock\SystemClock;
use Orisai\Scheduler\Job\JobSchedule;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;

/**
 * @internal
 */
abstract class BaseExplainCommand extends Command
{

	protected ClockInterface $clock;

	public function __construct(?ClockInterface $clock)
	{
		parent::__construct();
		$this->clock = $clock ?? new SystemClock();
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
