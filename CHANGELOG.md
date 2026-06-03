# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [1.0.0] - 2026-06-03

### Added
- Eager Solr flush on save for `ext:solr` running in `monitoringType = 0`: items queued for the saved record's site are indexed synchronously instead of waiting for the next scheduler run.
- Pressure gate that skips the eager flush when the pending index-queue backlog exceeds `indexQueueLimit`, leaving bulk operations to the scheduler.
- Scheduler-activity gate that skips the eager flush while an Index Queue Worker task is running.
- Flush scoped to the saved record's site; if the site cannot be resolved, the flush is deferred to the scheduler rather than fanning out to other sites.
- Per-site control via the `solr_eager_flush_enabled` site-configuration key — run eager flush on some sites (e.g. a public website) and queue-based indexing on others (e.g. an intranet).
- Reachability ping before indexing, so a site whose Solr does not answer is skipped and never makes a save wait.
- Per-site fault isolation: a failure on one site is logged with its reason and never blocks the other sites or the save.
- Configuration options `typeFilter`, `indexQueueLimit` and `deltaMax`, parsed fail-closed; `deltaMax` is clamped to at least `indexQueueLimit`.

### Compatibility
- TYPO3 13.4 LTS and 14.3+, PHP 8.2–8.4. On TYPO3 14, `apache-solr-for-typo3/solr` is currently a pre-release.

[Unreleased]: https://github.com/wazum/solr-eager-flush/compare/1.0.0...HEAD
[1.0.0]: https://github.com/wazum/solr-eager-flush/releases/tag/1.0.0
