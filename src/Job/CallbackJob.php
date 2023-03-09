<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Job;

use Closure;
use ReflectionFunction;
use function str_ends_with;

final class CallbackJob implements Job
{

	/** @var Closure(): void */
	private Closure $callback;

	/**
	 * @param Closure(): void $callback
	 */
	public function __construct(Closure $callback)
	{
		$this->callback = $callback;
	}

	public function getName(): string
	{
		$ref = new ReflectionFunction($this->callback);
		$refName = $ref->getName();

		$class = $ref->getClosureScopeClass();
		if ($class !== null && !str_ends_with($refName, '{closure}')) {
			return "{$class->getName()}::$refName()";
		}

		return $refName;
	}

	public function run(): void
	{
		($this->callback)();
	}

}
