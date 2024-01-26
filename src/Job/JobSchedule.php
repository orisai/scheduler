<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Job;

use Closure;
use Cron\CronExpression;
use DateTimeZone;

final class JobSchedule
{

	/** @var Closure(): Job|null  */
	private ?Closure $jobConstructor;

	private Job $job;

	private CronExpression $expression;

	/** @var int<0, 30> */
	private int $repeatAfterSeconds;

	private ?DateTimeZone $timeZone;

	private function __construct()
	{
		// Private
	}

	/**
	 * @param int<0, 30> $repeatAfterSeconds
	 */
	public static function create(
		Job $job,
		CronExpression $expression,
		int $repeatAfterSeconds,
		?DateTimeZone $timeZone = null
	): self
	{
		$self = new self();
		$self->jobConstructor = null;
		$self->job = $job;
		$self->expression = clone $expression;
		$self->repeatAfterSeconds = $repeatAfterSeconds;
		$self->timeZone = $timeZone;

		return $self;
	}

	/**
	 * @param Closure(): Job $jobConstructor
	 * @param int<0, 30> $repeatAfterSeconds
	 */
	public static function createLazy(
		Closure $jobConstructor,
		CronExpression $expression,
		int $repeatAfterSeconds,
		?DateTimeZone $timeZone = null
	): self
	{
		$self = new self();
		$self->jobConstructor = $jobConstructor;
		$self->expression = clone $expression;
		$self->repeatAfterSeconds = $repeatAfterSeconds;
		$self->timeZone = $timeZone;

		return $self;
	}

	public function getJob(): Job
	{
		if ($this->jobConstructor !== null) {
			$this->job = ($this->jobConstructor)();
			$this->jobConstructor = null;
		}

		return $this->job;
	}

	public function getExpression(): CronExpression
	{
		return $this->expression;
	}

	/**
	 * @return int<0, 30>
	 */
	public function getRepeatAfterSeconds(): int
	{
		return $this->repeatAfterSeconds;
	}

	public function getTimeZone(): ?DateTimeZone
	{
		return $this->timeZone;
	}

}
