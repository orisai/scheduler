<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use DateTimeImmutable;

final class JobInfo
{

	/** @var string|int */
	private $id;

	private string $name;

	private string $expression;

	/** @var int<0, max> */
	private int $second;

	private DateTimeImmutable $start;

	/**
	 * @param string|int $id
	 * @param int<0, max> $second
	 */
	public function __construct($id, string $name, string $expression, int $second, DateTimeImmutable $start)
	{
		$this->id = $id;
		$this->name = $name;
		$this->expression = $expression;
		$this->second = $second;
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
	 * @return int<0, max>
	 */
	public function getSecond(): int
	{
		return $this->second;
	}

	public function getStart(): DateTimeImmutable
	{
		return $this->start;
	}

}
