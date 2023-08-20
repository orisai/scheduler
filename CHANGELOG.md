# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/orisai/scheduler/compare/1.0.0...v2.x)

### Changed

- `JobManager`
	- `getPair()` -> `getScheduledJob()`
	- `getPairs()` -> `getScheduledJobs()`
- `Scheduler`
	- `getJobs()` -> `getScheduledJobs()`
- `RunSummary`
	- `getJobs()` -> `getJobSummaries()`
- `JobExecutor`
  - `runJobs()` returns `Generator<int, JobSummary, void, RunSummary>` instead of `RunSummary`
