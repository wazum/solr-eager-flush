# Reference

Everything the [README](README.md) leaves out. Start there for what the extension does.

## Requirements

- TYPO3 13.4 LTS, or 14.3 and newer
- `apache-solr-for-typo3/solr` ^13.0 || ^14.0, in `monitoringType = 0` (the default)
- PHP 8.2, 8.3, 8.4 or 8.5

ext:solr 14 needs [Apache Solr 10](https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Appendix/VersionMatrix.html) and TYPO3 14.3. Installing this extension there needs nothing else.

The test matrix covers ext:solr 13 and 14, and the `IndexService` and `Queue` entry points this extension builds on are the same in both. ext:solr 14 did rework *how* an item is indexed, using an in-process frontend sub-request instead of an HTTP round-trip to the site, but that happens below those entry points.

`monitoringType = 1` (delayed) is not supported. In that mode ext:solr queues the save for its own event queue task, and the event this extension listens for arrives when that task runs, on the command line, where the flush stands down.

## Where the flush runs

At the end of the same request that saved the record, in the process that is already booted. Whether the editor waits for it depends on the server API.

| Server API | What happens |
|---|---|
| **PHP-FPM** | The response goes to the editor first, through [`fastcgi_finish_request()`](https://www.php.net/manual/en/function.fastcgi-finish-request.php), and the indexing runs after it. This is the case the extension is written for, and what nginx or Apache in front of PHP-FPM gives you. |
| **LiteSpeed** | The same, through `litespeed_finish_request()`. |
| **`mod_php`, CGI** | The flush still runs at the end of the request, but the connection cannot be released early, so the editor waits for it. |
| **CLI** | No flush. `typo3` commands, `scheduler:run` and imports leave their items to the Index Queue Worker. |

Two reasons for the command line to stand down. `DataHandler` fires the event that triggers the flush before it writes the MM relations of the record it just saved, so indexing there would send a new record to Solr without its categories or tags, and nothing would queue it again. And ext:solr emits one event per saved record, so an import of a few thousand records would run a full index cycle, including a Solr commit per language, for every one of them. Neither applies to a request, where the flush runs after `DataHandler` has finished.

> [!NOTE]
> Releasing the response frees the editor, not the process. The PHP worker stays busy until the flush finishes and counts against [`pm.max_children`](https://www.php.net/manual/en/install.fpm.configuration.php) while it does. The gates and `deltaMax` keep each flush bounded.

> [!WARNING]
> Persistent worker runtimes such as [FrankenPHP worker mode](https://frankenphp.dev/docs/worker/) are not supported. Their process is reused across requests, so the end-of-request hook does not fire per save. Leave such a site on queue-based indexing with the per-site key below.

## When the flush stands down

### The backlog gate

Skips when more than `indexQueueLimit` items are already pending for the site, which leaves bulk changes to the Index Queue Worker. The check is scoped to the saved record's site and to the configured `typeFilter`, so a backlog somewhere else does not suppress a save here.

### The scheduler gate

Skips while an Index Queue Worker task is executing, so the two do not index the same items and fire competing commits. An execution counts as running only inside the scheduler's own `maxLifetime` window, 24 hours by default, so a run that crashed and left its marker behind does not switch eager flush off until someone cleans up the task record.

### The per-site lock

Skips while another request is already flushing the same site. That flush reads the queue when it starts, so it usually carries the newly queued items along; anything queued after it started waits for the next save or for the scheduler. Without the lock, two saves would index the same items twice and send a Solr commit each, which can exceed Solr's `maxWarmingSearchers` and report a failure for documents that were written correctly.

Backing off beats waiting here. Waiting would hold a second PHP worker for work the first flush is doing anyway.

## What gets indexed

The flush is scoped to the site roots responsible for the saved record. Usually that is one, but a record shared across sites, through ext:solr's `additionalPageIds` for example, flushes every root it belongs to, exactly as the queue records them.

A root is skipped when it cannot be resolved, when eager flush is switched off for it, or when its Solr does not answer a quick ping. Otherwise its queued items are indexed, up to `deltaMax` and limited to the `typeFilter`.

> [!NOTE]
> "Immediate" means the document reaches Solr at the end of the save request. Whether it is *searchable* right away depends on your ext:solr and Solr commit configuration. With commits disabled the document is indexed but stays invisible until Solr next commits. Hard-committing on every editorial save is expensive, so most setups want a short auto-commit instead.

## Settings

Under **Settings → Extension Configuration → solr_eager_flush**.

### `typeFilter`

Default `records`. Which item types to flush: `records`, `pages` or `both`.

Pages are opt-in because ext:solr indexes a page by rendering it, on ext:solr 13 through an HTTP request back to the site and on 14 through an in-process frontend sub-request per language and access group. With the response already released the editor does not wait for that, but the PHP worker does.

In the default `records` mode nothing about a page reaches Solr eagerly. That covers saving the page itself, and editing a content element, which enqueues its containing page rather than a `tt_content` item. Such a save logs `eager-flush skipped` with the reason `no-pending-items`, and the page waits for the Index Queue Worker.

### `indexQueueLimit`

Default `5`, clamped to `100`. The backlog above which the flush stands down.

### `deltaMax`

Default `10`, clamped to `100`. The most items one request will index per site root. The clamp is what keeps a configuration typo from turning a single end-of-request callback into an unbounded indexing job.

`deltaMax` is raised to at least `indexQueueLimit` automatically. Otherwise the indexing window could be filled by items the `typeFilter` then discards, leaving eligible items unflushed until the next scheduler run.

## Per-site control

Eager flush is on for every site. To run it on some sites and not others, immediate indexing on a public website but queue-based indexing on an intranet for instance, opt a site out in `config/sites/<identifier>/config.yaml`:

```yaml
solr_eager_flush_enabled: false
```

Sites without the key keep eager flush on. This is also the kill switch to reach for in production, without uninstalling anything.

## Reading the log

Failures are warnings and errors, which TYPO3's default log configuration writes. Everything the flush *decided*, which root it indexed and why it stood down, is logged at info level, below that default. To follow it while working out why a save did not reach Solr, lower the threshold for this extension's channel in `config/system/additional.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Wazum']['SolrEagerFlush']['writerConfiguration'] = [
    \TYPO3\CMS\Core\Log\LogLevel::INFO => [
        \TYPO3\CMS\Core\Log\Writer\FileWriter::class => ['logFileInfix' => 'solr_eager_flush'],
    ],
];
```

Every record names the affected `root`. `eager-flush completed` means at least one root was indexed. `eager-flush skipped` carries a `reason`:

| Reason | What happened |
|---|---|
| `gate` | An Index Queue Worker task is executing. |
| `queue-pressure` | More items were pending than `indexQueueLimit`, which the record names as `limit`. |
| `already-flushing` | Another request holds the site's lock. |
| `deferral-unsupported` | A command-line save. |
| `no-pending-items` | ext:solr queued nothing for this save, nothing matched the `typeFilter`, or the items carry errors from an earlier indexing attempt. |
| `sites-skipped` | Every affected root was skipped, with a `skipped` map of root to `policy-disabled` or `solr-unreachable`. |

An item that failed to index keeps its error in `tx_solr_indexqueue_item.errors`, and both this extension and ext:solr's own worker skip it from then on. Clear the error in the ext:solr backend module to queue it again.

## Solr timeouts

> [!TIP]
> Configure a short Solr connection timeout. A Solr that *refuses* the connection fails instantly, but an unreachable host can otherwise stall the ping.
>
> Consider a request timeout as well. TYPO3 ships `$GLOBALS['TYPO3_CONF_VARS']['HTTP']['timeout'] = 0`, so a Solr that accepts the connection and then stops answering keeps the PHP process waiting indefinitely. Once the response has been released, neither `max_execution_time`, which does not count time spent waiting on a socket, nor FPM's `request_terminate_timeout`, which by default no longer applies after `fastcgi_finish_request()`, puts an end to it. Such a Solr can tie up one worker after another.
