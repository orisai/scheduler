<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use Cron\CronExpression;
use DateTimeImmutable;
use Throwable;

final class JobResult
{

	private CronExpression $expression;

	private DateTimeImmutable $end;

	private ?Throwable $throwable;

	public function __construct(CronExpression $expression, DateTimeImmutable $end, ?Throwable $throwable)
	{
		$this->expression = $expression;
		$this->end = $end;
		$this->throwable = $throwable;
	}

	public function getEnd(): DateTimeImmutable
	{
		return $this->end;
	}

	public function getThrowable(): ?Throwable
	{
		return $this->throwable;
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

}
