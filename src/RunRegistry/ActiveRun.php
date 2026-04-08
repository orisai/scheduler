<?php declare(strict_types = 1);

namespace Orisai\Scheduler\RunRegistry;

final class ActiveRun
{

	private string $id;

	private ?int $pid;

	private ?int $startTimestamp;

	public function __construct(string $id, ?int $pid = null, ?int $startTimestamp = null)
	{
		$this->id = $id;
		$this->pid = $pid;
		$this->startTimestamp = $startTimestamp;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getPid(): ?int
	{
		return $this->pid;
	}

	public function getStartTimestamp(): ?int
	{
		return $this->startTimestamp;
	}

}
