# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/orisai/scheduler/compare/1.0.0...HEAD)

## [1.0.0](https://github.com/orisai/scheduler/releases/tag/1.0.0) - 2023-03-21

### Added

- `Scheduler` interface
	- `ManagedScheduler`
	- `SimpleScheduler`
- `Job` interface
	- `CallbackJob`
- `Manager` interface
	- `SimpleJobManager`
	- `CallbackJobManager`
- `Executor` interface
	- `BasicJobExecutor`
	- `ProcessJobExecutor`
- Commands
	- `scheduler:list`
	- `scheduler:run`
	- `scheduler:run-job`
	- `scheduler:worker`
- Status
	- `JobInfo`
	- `JobResult`
	- `JobSummary`
	- `RunSummary`
- Exceptions
	- `JobFailure`
	- `RunFailure`
	- `JobProcessFailure`
