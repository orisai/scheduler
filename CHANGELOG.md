# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/orisai/scheduler/compare/2.2.2...v2.x)

### Added

- Maintenance mode - stop running jobs during deployments
	- `MaintenanceChecker` interface - implement to define when maintenance is active
	- `MaintenanceManager` - orchestrates maintenance checking and shutdown requests
- Run tracking
	- `RunRegistry` - tracks active runs
		- `FileRunRegistry` - PID-based stale detection (verifies process is alive), JSON metadata in run files
		- `LockPoolRunRegistry` - lock refresh support to prevent TTL expiry during long runs
	- `ActiveRun` - value object with run ID, PID and start timestamp
	- `ActivityStatus` - value object returned by `ManagedScheduler->getStatus()`
- `StatusCommand` (`scheduler:status`) - reports maintenance state and active runs
	- `--fail-when-not-ready-for-shutdown` option for deploy scripts
- `Scheduler`
	- `getStatus()` - returns `ActivityStatus` with active runs and maintenance state (BC break)
	- `runJob($id, force: true)` ignores maintenance mode
- `ManagedScheduler`
	- accepts optional `MaintenanceManager` - enables maintenance mode with two-phase shutdown
	  (graceful wait, then force-kill after configurable grace period)
	- accepts optional `RunRegistry` - enables active run tracking
- `RunCommand`
	- accepts optional `MaintenanceManager` - registers signal handlers for graceful shutdown
	- handles `SIGTERM` and `SIGINT` signals (requires `pcntl` extension, double-signal forces exit)
	- returns exit code `2` when run was stopped due to maintenance
	- `--force` parameter ignores maintenance mode
- `WorkerCommand`
	- handles `SIGTERM` and `SIGINT` signals for graceful stop (requires `pcntl` extension, double-signal forces exit)
- `BasicJobExecutor` - supports maintenance mode (shutdown after current job finishes)
- `JobResultState::maintenance()` - for jobs skipped or terminated due to maintenance
- `RunSummary->isMaintenanceActive()` - indicates whether run was affected by maintenance
- Multi-server protection via minute lock - prevents the same job from running twice within the same
  minute when scheduler runs on multiple servers with a distributed lock store

### Changed

- `JobExecutor` (BC break)
	- `runJobs()` accepts optional `?ShutdownCheck $shutdownCheck` parameter
- `RunSummary`
	- constructor accepts optional `bool $maintenanceActive` parameter

## [2.2.2](https://github.com/orisai/scheduler/compare/2.2.1...2.2.2) - 2026-02-12

### Fixed

- Use ANSI colors in console commands to support environments without true color support

## [2.2.1](https://github.com/orisai/scheduler/compare/2.2.0...2.2.1) - 2025-09-08

### Fixed

- `WorkerCommand` - run subprocess may write to output right before finish
- `ProcessJobExecutor`, `WorkerCommand` - parameters for phpdbg are properly escaped

## [2.2.0](https://github.com/orisai/scheduler/compare/2.1.3...2.2.0) - 2025-06-28

### Added

- `ProcessJobExecutor`, `WorkerCommand`
	- `PhpExecutableFinder` is used instead of `PHP_BINARY` to locate PHP binary (fixes running in environment where
	  `PHP_BINARY` is not available)

## [2.1.3](https://github.com/orisai/scheduler/compare/2.1.2...2.1.3) - 2024-12-29

### Changed

- Composer
	- Allow PHP 8.4

## [2.1.2](https://github.com/orisai/scheduler/compare/2.1.1...2.1.2) - 2024-07-03

### Fixed

- `ProcessJobExecutor`
	- start and end times passed from job process to main process are not shifted by offset of current timezone from UTC
- `ListCommand`
	- handle impossible due times (like 31st of February)

## [2.1.1](https://github.com/orisai/scheduler/compare/2.1.0...2.1.1) - 2024-06-21

### Added

- callbacks are marked as either `@param-later-invoked-callable` or `@param-immediately-invoked-callable`
- Allow PHP 8.3
- Allow symfony/console, symfony/lock and symfony/process ^7.0.0

## [2.1.0](https://github.com/orisai/scheduler/compare/2.0.0...2.1.0) - 2024-05-26

### Added

- `ManagedScheduler`
	- warns in case lock was released before job finished (via optional logger)
- `ExplainCommand`
	- explains cron expression syntax
- `ListCommand`
	- adds `--explain` option to explain whole expression
- `SymfonyConsoleJob`
- `JobInfo`
	- `getTimeZone()` returns timezone job should run in
	- `isForcedRun()`returns whether job was run via $scheduler->runJob() or scheduler:run-job command, ignoring the cron expression
- `PlannedJobInfo`
	- `getTimeZone()`

### Changed

- `ProcessJobExecutor`
	- subprocess errors include exit code
	- logs unexpected stdout instead of triggering E_USER_NOTICE (via optional logger)
	- logs unexpected stderr instead of throwing an exception (via optional logger)
- `JobInfo`
	- `getExtendedExpression()` includes seconds only if seconds are used
	- `getExtendedExpression()` includes timezone if timezone is used
- `ListCommand`
	- more compact render
	- sort jobs by keys instead of generated names
- `WorkerCommand`
	- requires interactive CLI
	- `--force` option to bypass interactive CLI requirement

### Fixed

- `ListCommand`
	- on invalid input option - writes error to output instead of throwing exception
- `JobInfo`
	- `getStart()` includes correct timezone (in main process, when using `ProcessJobExecutor`)
- `JobResult`
	- `getEnd()` includes correct timezone (in main process, when using `ProcessJobExecutor`)

## [2.0.0](https://github.com/orisai/scheduler/compare/1.0.0...2.0.0) - 2024-01-26

This release most notably contains:

- planning jobs by seconds
- timezones support
- locked job, before run and after run events
- job results are shown in console immediately
- stderr handling in subprocesses - causes an exception
- stdout handling in subprocesses - causes a notice, instead of an exception
- simplified job manager

### Added

- `Scheduler`
	- `runPromise()` - allows `scheduler:run` and `scheduler:work` commands to output job result as soon as it is
	  finished
- `SimpleScheduler`
	- `addJob()` accepts parameter `repeatAfterSeconds`
	- `addJob()` accepts parameter `timeZone`
	- `addLazyJob()` replaces `CallbackJobManager`
- `ManagedScheduler`
	- `addLockedJobCallback()` - executes given callback when job is locked
	- `addBeforeRunCallback()` - executes given callback before run starts
	- `addAfterRunCallback()` - executes given callback when run finishes
- `JobInfo`
	- `getRepeatAfterSeconds()`- returns the seconds part of expression
	- `getExtendedExpression()` - returns cron expression including seconds
	- `getRunSecond()`- returns for which second within a minute was job scheduled
- `JobSchedule` - contains info about the scheduled job
- `SimpleJobManager`
	- `addJob()` accepts parameter `repeatAfterSeconds`
	- `addJob()` accepts parameter `timeZone`
	- `addLazyJob()` replaces `CallbackJobManager`
- `ListCommand`
	- prints `repeatAfterSeconds` parameter
	- prints job's `timeZone` parameter
	- adds `--timezone` (`-tz`) option to show execution times in specified timezone
- `RunJobCommand`
	- stdout is caught in a buffer and printed to output in a standardized manner (to above job result by default and
	  into key `stdout` in case `--json` option is used)

### Changed

- `JobManager`
	- `getPair()` -> `getJobSchedule()`
		- returns `JobSchedule` instead of an array
	- `getPairs()` -> `getJobSchedules()`
		- returns array of `JobSchedule` instead of an array of arrays
- `Scheduler`
	- `getJobs()` -> `getJobSchedules()`
		- returns array of `JobSchedule` instead of an array of arrays
- `RunSummary`
	- `getJobs()` -> `getJobSummaries()`
- `JobInfo`
	- `getStart()->getTimeZone()` - returns timezone specified by the job
- `JobResult`
	- `getEnd()->getTimeZone()` - returns timezone specified by the job
- `JobResultState`
	- `skip()` renamed to `lock()`
- `JobExecutor`
	- `runJobs()` accepts list of `JobSchedule` grouped by seconds instead of list of ids
	- `runJobs()` returns `Generator<int, JobSummary, void, RunSummary>` instead of `RunSummary`
- `ProcessJobExecutor`
	- uses microseconds instead of milliseconds for start and end times
	- better exception message in case subprocess call failed
	- handles stdout and stderr separately
		- stderr output does not make the job processing fail
		- if stderr output is produced, an exception is still thrown (explaining unexpected stderr instead of a job
		  failure)
		- stdout output is captured and converted to notice (with strict error handler it will still cause an exception,
		  but will not block execution of other jobs)
- `ManagedScheduler`
	- acquired job locks are scoped just to their id - changing run frequency or job name will not make process loose
	  the lock
- `CronExpression` is cloned after being added to job manager or scheduler for job immutability

### Removed

- `JobManager`
	- `getExpressions()` - replaced by `getJobSchedules()`
- `CallbackJobManager` - use `SimpleJobManager->addLazyJob()` instead

### Fixed

- `ProcessJobExecutor`
	- use existing CronExpression instance instead of creating new one to support inheritance
- `ListCommand`
	- Fix numeric job ids in case option `--next` is used
