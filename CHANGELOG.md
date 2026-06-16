# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed
- The `typeFilter` is now applied when selecting index-queue items, not after. A backlog of excluded item types (for example pages in the default `records` mode) could previously fill the indexing window and starve an eligible record, leaving it unindexed while the run still reported success.

### Changed
- The flush now runs at the end of the request instead of inside the save. On PHP-FPM and LiteSpeed the response is released to the editor first (`fastcgi_finish_request()` / `litespeed_finish_request()`), so the save returns immediately and indexing happens afterwards in the same process. Under the CLI the flush runs inline as before. Persistent worker runtimes (FrankenPHP worker mode) are not supported; leave such sites on queue-based indexing.
- Several saves within one request are collapsed into a single flush per affected site; gates, pressure check and Solr ping run once per request instead of once per save event.

## [1.2.0] - 2026-06-04

### Fixed
- The per-site `solr_eager_flush_enabled` setting now parses string/quoted boolean values correctly; a YAML `'false'` no longer accidentally enables eager flush.

### Changed
- The index-queue pressure check is now scoped to the saved record's site and to the configured `typeFilter`, using a bounded query instead of a full count. A backlog on another site — or of item types you don't eager-flush — no longer suppresses an eligible save.

## [1.1.0] - 2026-06-03

### Changed
- Default `typeFilter` is now `records` instead of `both`; pages are opt-in via `pages`/`both`. ext:solr indexes a page by rendering it, which might be too heavy to run synchronously on every save.

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

[Unreleased]: https://github.com/wazum/solr-eager-flush/compare/1.2.0...HEAD
[1.2.0]: https://github.com/wazum/solr-eager-flush/releases/tag/1.2.0
[1.1.0]: https://github.com/wazum/solr-eager-flush/releases/tag/1.1.0
[1.0.0]: https://github.com/wazum/solr-eager-flush/releases/tag/1.0.0
