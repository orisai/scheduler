<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use DateTimeImmutable;

final class JobInfo
{

	/** @var string|int */
	private $id;

	private string $name;

	private string $expression;

	/** @var int<0, 30> */
	private int $repeatAfterSeconds;

	/** @var int<0, max> */
	private int $runSecond;

	private DateTimeImmutable $start;

	/**
	 * @param string|int  $id
	 * @param int<0, 30>  $repeatAfterSeconds
	 * @param int<0, max> $runSecond
	 */
	public function __construct(
		$id,
		string $name,
		string $expression,
		int $repeatAfterSeconds,
		int $runSecond,
		DateTimeImmutable $start
	)
	{
		$this->id = $id;
		$this->name = $name;
		$this->expression = $expression;
		$this->repeatAfterSeconds = $repeatAfterSeconds;
		$this->runSecond = $runSecond;
		$this->start = $start;
	}

	/**
	 * @return string|int
	 */
	public function getId()
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
	 * Expression[ / repeat after seconds]
	 */
	public function getExtendedExpression(): string
	{
		$expression = $this->expression;

		if ($this->repeatAfterSeconds !== 0) {
			$expression .= " / $this->repeatAfterSeconds";
		}

		return $expression;
	}

	/**
	 * @return int<0, max>
	 */
	public function getRunSecond(): int
	{
		return $this->runSecond;
	}

	public function getStart(): DateTimeImmutable
	{
		return $this->start;
	}

	/**
	 * @return array<mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			'expression' => $this->getExpression(),
			'repeatAfterSeconds' => $this->getRepeatAfterSeconds(),
			'runSecond' => $this->getRunSecond(),
			'start' => $this->getStart()->format('U.u'),
		];
	}

}
