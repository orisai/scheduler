<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

use ValueError;

final class JobResultState
{

	private const Done = 1,
		Fail = 2,
		Skip = 3;

	private const ValuesAndNames = [
		self::Done => 'Done',
		self::Fail => 'Fail',
		self::Skip => 'Skip',
	];

	/** @readonly */
	public string $name;

	/** @readonly */
	public int $value;

	/** @var array<string, self> */
	private static array $instances = [];

	private function __construct(string $name, int $value)
	{
		$this->name = $name;
		$this->value = $value;
	}

	public static function done(): self
	{
		return self::from(self::Done);
	}

	public static function fail(): self
	{
		return self::from(self::Fail);
	}

	public static function skip(): self
	{
		return self::from(self::Skip);
	}

	public static function tryFrom(int $value): ?self
	{
		$key = self::ValuesAndNames[$value] ?? null;

		if ($key === null) {
			return null;
		}

		return self::$instances[$key] ??= new self($key, $value);
	}

	public static function from(int $value): self
	{
		$self = self::tryFrom($value);

		if ($self === null) {
			throw new ValueError();
		}

		return $self;
	}

	/**
	 * @return array<self>
	 */
	public static function cases(): array
	{
		$cases = [];
		foreach (self::ValuesAndNames as $value => $name) {
			$cases[] = self::from($value);
		}

		return $cases;
	}

}
