<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Job;

use Cron\CronExpression;

final class JobSchedule
{

	private Job $job;

	private CronExpression $expression;

	/** @var int<0, 30> */
	private int $repeatAfterSeconds;

	/**
	 * @param int<0, 30> $repeatAfterSeconds
	 */
	public function __construct(
		Job $job,
		CronExpression $expression,
		int $repeatAfterSeconds
	)
	{
		$this->job = $job;
		$this->expression = $expression;
		$this->repeatAfterSeconds = $repeatAfterSeconds;
	}

	public function getJob(): Job
	{
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
