<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use DateTimeImmutable;

final class JobInfo
{

	/** @var string|int */
	private $id;

	private string $name;

	private string $expression;

	private DateTimeImmutable $start;

	/**
	 * @param string|int $id
	 */
	public function __construct($id, string $name, string $expression, DateTimeImmutable $start)
	{
		$this->id = $id;
		$this->name = $name;
		$this->expression = $expression;
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

	public function getStart(): DateTimeImmutable
	{
		return $this->start;
	}

}
