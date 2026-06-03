<p align="center">
  <img src="Resources/Public/Icons/Extension.svg" alt="Solr Eager Flush" width="80" height="80">
</p>

<h1 align="center">Solr Eager Flush for TYPO3</h1>

[![Tests](https://github.com/wazum/solr-eager-flush/actions/workflows/tests.yml/badge.svg)](https://github.com/wazum/solr-eager-flush/actions/workflows/tests.yml)
[![Packagist Version](https://img.shields.io/packagist/v/wazum/solr-eager-flush.svg)](https://packagist.org/packages/wazum/solr-eager-flush)
[![Supported TYPO3](https://img.shields.io/badge/TYPO3-13.4%20%7C%2014.3-orange.svg)](https://get.typo3.org/)
[![Supported PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0-blue.svg)](LICENSE)

Push editorial changes into Solr **the moment a record is saved**, instead of waiting for the next index-queue scheduler run.

With `apache-solr-for-typo3/solr` in its default `monitoringType = 0` (Immediate) mode, saving a record only *enqueues* it in `tx_solr_indexqueue_item`; the document reaches Solr later, when the **Index Queue Worker** scheduler task next runs. This extension closes that gap: after a save it indexes the freshly queued items synchronously. Built-in gates back off under queue pressure or while the scheduler is already working, so the eager flush stays out of the way when it would cost too much — how a save actually performs still depends on your content and Solr setup.

See [CHANGELOG.md](CHANGELOG.md) for release notes.

## Requirements

- TYPO3 13.4 LTS or 14.3+
- `apache-solr-for-typo3/solr` ^13.0 || ^14.0 in `monitoringType = 0` (the default)
- PHP 8.2, 8.3 or 8.4

> [!NOTE]
> On TYPO3 14, `apache-solr-for-typo3/solr` is currently available only as a pre-release (`^14.0@beta`). Allow beta stability in your project (`composer config minimum-stability beta && composer config prefer-stable true`) before requiring this extension there.

## Installation

```bash
composer require wazum/solr-eager-flush
```

## How it works

After a record is saved, ext:solr updates its index queue and fires a `ProcessingFinishedEvent`. The extension listens for that event and indexes the queued items right away — unless a gate tells it to back off:

- **Pressure gate** — skips when more than `indexQueueLimit` items are already pending, leaving bulk changes to the scheduler.
- **Scheduler-activity gate** — skips while an Index Queue Worker task is running, to avoid competing with it.

When both gates pass, the flush is scoped to the saved record's site; if that site can't be resolved, the flush is skipped and the items are left to the scheduler (a single save never fans out to other sites). The site is also skipped when eager flush is disabled for it, or when its Solr doesn't answer a quick ping — so an unreachable Solr never makes the save wait. Otherwise its items are indexed up to `deltaMax`, limited to the configured `typeFilter`. A failure on one site is logged and never blocks the save.

## Configuration

Configure via the TYPO3 backend under **Settings → Extension Configuration → solr_eager_flush**:

| Setting | Default | Description |
|---|---|---|
| `typeFilter` | `both` | Which item types to eager-flush: `records`, `pages`, or `both`. |
| `indexQueueLimit` | `5` | Skip the eager flush when more than this many pending index-queue items already exist. |
| `deltaMax` | `10` | Maximum index-queue items to index per invocation, per affected site root. |

> [!NOTE]
> `deltaMax` is automatically raised to at least `indexQueueLimit`. Otherwise the `deltaMax`-sized indexing window could be filled by items the `typeFilter` then discards, leaving allowed items unflushed until the next scheduler run.

### Per-site control

Eager flush is enabled for every site by default. To run it only on some sites — for example, immediate indexing on a public website but queue-based indexing on an intranet — opt a site out in its site configuration (`config/sites/<identifier>/config.yaml`):

```yaml
solr_eager_flush_enabled: false
```

Sites without the key keep eager flush enabled. This also serves as a per-site kill switch in production without uninstalling the extension.

## License

GPL-2.0-or-later
