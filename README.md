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

With `apache-solr-for-typo3/solr` in its default [`monitoringType = 0` (Immediate)](https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Configuration/Reference/ExtensionSettings.html) mode, saving a record only *enqueues* it in `tx_solr_indexqueue_item`; the document reaches Solr later, when the [**Index Queue Worker**](https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Backend/IndexQueue.html) scheduler task next runs. This extension closes that gap: it indexes the freshly queued items at the end of the save request — on PHP-FPM and LiteSpeed *after* the response has been sent to the editor, so the save itself stays fast. Built-in gates back off under queue pressure or while the scheduler is already working, so the eager flush stays out of the way when it would cost too much.

See [CHANGELOG.md](CHANGELOG.md) for release notes.

## Requirements

- TYPO3 13.4 LTS or 14.3+
- `apache-solr-for-typo3/solr` ^13.0 || ^14.0 in `monitoringType = 0` (the default)
- PHP 8.2, 8.3 or 8.4

> [!NOTE]
> On TYPO3 14, `apache-solr-for-typo3/solr` is currently available only as a pre-release (`^14.0@beta`). Allow beta stability in your project ([`composer config minimum-stability beta && composer config prefer-stable true`](https://getcomposer.org/doc/04-schema.md#minimum-stability)) before requiring this extension there.

## Installation

```bash
composer require wazum/solr-eager-flush
```

## How it works

After a record is saved, ext:solr updates its index queue and fires a [`ProcessingFinishedEvent`](https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Development/Events.html). This extension listens for that event, resolves the saved record's site, and schedules an index flush for the end of the request. Several saves in one request collapse into a single flush per affected site.

### When the flush runs

The flush runs at the end of the same, already-booted request — but whether the editor waits for it depends on the server API:

- **PHP-FPM and LiteSpeed** — the response is released to the editor first ([`fastcgi_finish_request()`](https://www.php.net/manual/en/function.fastcgi-finish-request.php), or its LiteSpeed equivalent [`litespeed_finish_request()`](https://www.litespeedtech.com/open-source/litespeed-sapi/lsapi-release-log)), so the save returns immediately and indexing happens afterwards, with no waiting. This is the case the extension is built for, and what most TYPO3 setups run (nginx or Apache in front of PHP-FPM).
- **Other per-request SAPIs** (Apache `mod_php`, CGI) — the flush still runs at the end of the request, but the connection can't be released early, so the editor waits for it much as they would for inline indexing.

> [!NOTE]
> Even when the response is released early, the PHP process stays busy until the flush finishes — it counts against your FPM worker pool ([`pm.max_children`](https://www.php.net/manual/en/install.fpm.configuration.php)). The gates and `deltaMax` keep each flush bounded.

> [!WARNING]
> Persistent worker runtimes ([FrankenPHP worker mode](https://frankenphp.dev/docs/worker/)) are not supported for the eager flush: their process is reused across requests, so the end-of-request hook would not fire per save. Leave such a site on queue-based indexing — opt it out with the per-site key below.

### When the flush backs off

Before indexing, two gates can tell the flush to stand down and leave the work to the scheduler:

- **Pressure gate** — skips when more than `indexQueueLimit` items are already pending, leaving bulk changes to the scheduler.
- **Scheduler-activity gate** — skips while an Index Queue Worker task is running, to avoid competing with it.

### What gets indexed

The flush is scoped to the roots responsible for the saved record — usually one, but a record shared across sites (for example through ext:solr's `additionalPageIds`) flushes every root it belongs to, exactly as the index queue records them. A root is skipped when:

- it can't be resolved (the items are left to the scheduler),
- eager flush is disabled for it, or
- its Solr doesn't answer a quick ping.

Otherwise each root's queued items are indexed right away, up to `deltaMax` and limited to the configured `typeFilter`. Any failure is logged and never breaks the save.

> [!TIP]
> Configure a short Solr connection timeout. A Solr that *refuses* the connection fails instantly, but an *unreachable* host could otherwise stall the ping.

## Configuration

Configure via the TYPO3 backend under **Settings → Extension Configuration → solr_eager_flush**:

| Setting | Default | Description |
|---|---|---|
| `typeFilter` | `records` | Which item types to eager-flush: `records`, `pages`, or `both`. Defaults to `records` because ext:solr indexes a page by rendering it, which is comparatively heavy. With the response released early the editor no longer waits for it, but the PHP process does — set `pages` or `both` to opt in. |
| `indexQueueLimit` | `5` | Skip the eager flush when more than this many pending index-queue items already exist. Clamped to `100`. |
| `deltaMax` | `10` | Maximum index-queue items to index per invocation, per affected site root. Clamped to `100` to keep a single request's synchronous flush bounded. |

> [!NOTE]
> `deltaMax` is automatically raised to at least `indexQueueLimit`. Otherwise the `deltaMax`-sized indexing window could be filled by items the `typeFilter` then discards, leaving allowed items unflushed until the next scheduler run.

> [!NOTE]
> Editing a content element (`tt_content`) enqueues its **containing page**, not a `tt_content` item. In the default `records` mode those page items are excluded, so ordinary content edits are not eager-flushed — set `typeFilter` to `pages` or `both` if you want content edits to reach Solr immediately.

> [!NOTE]
> "Immediate" means the document reaches Solr at the end of the save request. Whether it becomes *searchable* right away still depends on your ext:solr/Solr commit configuration (hard commit vs. auto/soft commit). With commits disabled the document is indexed but stays invisible until Solr next commits; hard-committing on every editorial save, on the other hand, is expensive.

### Per-site control

Eager flush is enabled for every site by default. To run it only on some sites — for example, immediate indexing on a public website but queue-based indexing on an intranet — opt a site out in its site configuration (`config/sites/<identifier>/config.yaml`):

```yaml
solr_eager_flush_enabled: false
```

Sites without the key keep eager flush enabled. This also serves as a per-site kill switch in production without uninstalling the extension.

## License

GPL-2.0-or-later
