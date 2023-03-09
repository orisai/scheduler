<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use DateTimeImmutable;

final class JobInfo
{

	private string $expression;

	private DateTimeImmutable $start;

	public function __construct(string $expression, DateTimeImmutable $start)
	{
		$this->expression = $expression;
		$this->start = $start;
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
