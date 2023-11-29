<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

/**
 * @internal
 */
final class RunParameters
{

	/** @var int<0, max> */
	private int $second;

	/**
	 * @param int<0, max> $second
	 */
	public function __construct(int $second)
	{
		$this->second = $second;
	}

	/**
	 * @param array<mixed> $raw
	 */
	public static function fromArray(array $raw): self
	{
		return new self($raw['second']);
	}

	/**
	 * @return int<0, max>
	 */
	public function getSecond(): int
	{
		return $this->second;
	}

	/**
	 * @return array<mixed>
	 */
	public function toArray(): array
	{
		return [
			'second' => $this->second,
		];
	}

}
