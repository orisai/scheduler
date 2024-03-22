<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use Cron\CronExpression;
use DateTimeImmutable;

final class JobResult
{

	private CronExpression $expression;

	private DateTimeImmutable $end;

	private JobResultState $state;

	public function __construct(CronExpression $expression, DateTimeImmutable $end, JobResultState $state)
	{
		$this->expression = $expression;
		$this->end = $end;
		$this->state = $state;
	}

	public function getEnd(): DateTimeImmutable
	{
		return $this->end;
	}

	public function getState(): JobResultState
	{
		return $this->state;
	}

	/**
	 * @param int<0, max> $nth
	 *
	 * @infection-ignore-all
	 */
	public function getNextRunDate(int $nth = 0): DateTimeImmutable
	{
		return DateTimeImmutable::createFromMutable(
			$this->expression->getNextRunDate($this->end, $nth),
		);
	}

	/**
	 * @param int<0, max> $total
	 * @return list<DateTimeImmutable>
	 */
	public function getNextRunDates(int $total): array
	{
		$dates = [];
		foreach ($this->expression->getMultipleRunDates($total, $this->end) as $date) {
			$dates[] = DateTimeImmutable::createFromMutable($date);
		}

		return $dates;
	}

	/**
	 * @return array<mixed>
	 */
	public function toArray(): array
	{
		return [
			'end' => $this->getEnd()->format('U.u e'),
			'state' => $this->getState()->value,
		];
	}

}
