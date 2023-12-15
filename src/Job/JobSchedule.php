<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Job;

use Closure;
use Cron\CronExpression;

final class JobSchedule
{

	/** @var Closure(): Job|null  */
	private ?Closure $jobConstructor;

	private Job $job;

	private CronExpression $expression;

	/** @var int<0, 30> */
	private int $repeatAfterSeconds;

	/**
	 * @param int<0, 30> $repeatAfterSeconds
	 */
	public static function create(
		Job $job,
		CronExpression $expression,
		int $repeatAfterSeconds
	): self
	{
		$self = new self();
		$self->jobConstructor = null;
		$self->job = $job;
		$self->expression = $expression;
		$self->repeatAfterSeconds = $repeatAfterSeconds;

		return $self;
	}

	/**
	 * @param Closure(): Job $jobConstructor
	 * @param int<0, 30> $repeatAfterSeconds
	 */
	public static function createLazy(
		Closure $jobConstructor,
		CronExpression $expression,
		int $repeatAfterSeconds
	): self
	{
		$self = new self();
		$self->jobConstructor = $jobConstructor;
		$self->expression = $expression;
		$self->repeatAfterSeconds = $repeatAfterSeconds;

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

}
