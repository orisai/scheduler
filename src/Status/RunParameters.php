<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

/**
 * @internal
 */
final class RunParameters
{

	/** @var int<0, max> */
	private int $second;

	private bool $manualRun;

	/**
	 * @param int<0, max> $second
	 */
	public function __construct(int $second, bool $manualRun)
	{
		$this->second = $second;
		$this->manualRun = $manualRun;
	}

	/**
	 * @param array<mixed> $raw
	 */
	public static function fromArray(array $raw): self
	{
		return new self($raw['second'], $raw['manualRun']);
	}

	/**
	 * @return int<0, max>
	 */
	public function getSecond(): int
	{
		return $this->second;
	}

	public function isManualRun(): bool
	{
		return $this->manualRun;
	}

	/**
	 * @return array<mixed>
	 */
	public function toArray(): array
	{
		return [
			'second' => $this->second,
			'manualRun' => $this->manualRun,
		];
	}

}
