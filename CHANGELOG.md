# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/orisai/scheduler/compare/1.0.0...v2.x)

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
	- adds `-n` shortcut for `--next` option
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
