# Solr Eager Flush for TYPO3

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

When both gates pass, the queued items are indexed per affected site (up to `deltaMax` each), limited to the configured `typeFilter`. If indexing fails, the error is logged and the save still completes normally.

## Configuration

Configure via the TYPO3 backend under **Settings → Extension Configuration → solr_eager_flush**:

| Setting | Default | Description |
|---|---|---|
| `typeFilter` | `both` | Which item types to eager-flush: `records`, `pages`, or `both`. |
| `indexQueueLimit` | `5` | Skip the eager flush when more than this many pending index-queue items already exist. |
| `deltaMax` | `10` | Maximum index-queue items to index per invocation, per affected site root. |

> [!TIP]
> Keep `deltaMax` greater than or equal to `indexQueueLimit`. The pressure gate counts pending items before indexing, while `deltaMax` caps how many are indexed per site — setting the cap lower than the limit can leave allowed items behind for the next scheduler run.

## License

GPL-2.0-or-later
