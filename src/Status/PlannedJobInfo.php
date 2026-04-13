<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use DateTimeImmutable;
use DateTimeZone;

final class PlannedJobInfo
{

	/** @var string|int */
	private $id;

	private string $name;

	private string $expression;

	/** @var int<0, 30> */
	private int $repeatAfterSeconds;

	private DateTimeImmutable $runStart;

	private ?int $runsCountPerMinute = null;

	/** @var non-empty-list<DateTimeImmutable>|null */
	private ?array $estimatedStartTimes = null;

	private ?DateTimeZone $timeZone;

	/**
	 * @param string|int $id
	 * @param int<0, 30> $repeatAfterSeconds
	 */
	public function __construct(
		$id,
		string $name,
		string $expression,
		int $repeatAfterSeconds,
		DateTimeImmutable $runStart,
		?DateTimeZone $timeZone
	)
	{
		$this->id = $id;
		$this->name = $name;
		$this->expression = $expression;
		$this->repeatAfterSeconds = $repeatAfterSeconds;
		$this->runStart = $runStart;
		$this->timeZone = $timeZone;
	}

	/**
	 * @return string|int
	 *
	 * @deprecated Use getJobId() instead. Will be removed in v3.0.
	 */
	public function getId()
	{
		return $this->id;
	}

	/**
	 * @return string|int
	 */
	public function getJobId()
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getExpression(): string
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

	/**
	 * Expression / repeat after seconds
	 */
	public function getExtendedExpression(): string
	{
		return "$this->expression / $this->repeatAfterSeconds";
	}

	/**
	 * @return non-empty-list<DateTimeImmutable>
	 */
	public function getEstimatedStartTimes(): array
	{
		if ($this->estimatedStartTimes !== null) {
			return $this->estimatedStartTimes;
		}

		$lastTime = $this->runStart;
		$times = [$this->runStart];
		$count = $this->getRunsCountPerMinute();
		for ($i = 1; $i < $count; $i++) {
			$lastTime = $lastTime->modify("+{$this->repeatAfterSeconds} seconds");
			$times[] = $lastTime;
		}

		return $this->estimatedStartTimes = $times;
	}

	/**
	 * @return int<1, max>
	 */
	public function getRunsCountPerMinute(): int
	{
		if ($this->runsCountPerMinute !== null) {
			return $this->runsCountPerMinute;
		}

		$count = 1;
		if ($this->repeatAfterSeconds > 0) {
			$count = (int) (60 / $this->repeatAfterSeconds);
		}

		return $this->runsCountPerMinute = $count;
	}

	public function getTimeZone(): ?DateTimeZone
	{
		return $this->timeZone;
	}

}
