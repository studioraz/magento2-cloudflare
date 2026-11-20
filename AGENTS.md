<!-- Managed by agent: keep guidance consistent with this module's source. -->
<!-- Last updated: 2026-11-20 -->

# AGENTS.md

**Precedence:** the closest `AGENTS.md` wins; inherit the host project's environment and command conventions.

## Module Snapshot

- Composer package `studioraz/magento2-cloudflare`, Magento module `SR_Cloudflare`, namespace `SR\Cloudflare`.
- Requires PHP ^8.1, Magento Framework ^103.0, SR Base ^1.5, and SR Gateway ^2.7; see `composer.json` and `etc/module.xml`.
- Magento Admin deploys one shared Worker per Cloudflare account, synchronizes selected zone routes, and switches the global FPC application. Image URL transformation is configured separately.

## Commands

Run from this module's root. The package has no Composer scripts or standalone Magento installation.

| Task | Command |
|------|---------|
| Worker syntax | `node --input-type=module --check < CFWorker/FPC-worker.js` |
| Worker tests | `node --test Test/Unit/CFWorker/FPCWorkerTest.mjs` |

Magento runtime checks use the host project and its command wrapper; in All4pet, use `ddev n98`.

## File Map

- `CFWorker/FPC-worker.js`: Cache API lookup/storage, request normalization, Magento vary context, cacheability, and diagnostic headers.
- `Plugin/AddCacheTagHeader.php` and `etc/frontend/di.xml`: propagate Magento tags on result rendering and HTTP response delivery.
- `Config/CacheConfig.php` and `etc/adminhtml/system.xml`: FPC settings, purge configuration, and hostname site tags.
- `Controller/Adminhtml/Worker` and `Model/Worker`: verify the saved token and zone selection, deploy the Worker, reconcile routes, and remove a confirmed deleted zone from Magento.
- `Model/CloudflareClient.php`, `Observer/PurgeByTags.php`, and `Observer/FlushAllCacheObserver.php`: Cloudflare API calls and Magento invalidation events; wiring in `etc/events.xml`.
- `Cron/ProcessPurgeQueue.php`, `Model/PurgeQueue/QueueRepository.php`, and `etc/db_schema.xml`: asynchronous purge batches, retries, and the `studioraz_cloudflare_purge_queue` table; cron wiring in `etc/crontab.xml`.
- `Helper/CloudflareUrlFormatHelper.php` and frontend image plugins: Cloudflare image transformation URLs.

## Cache Integration Rules

- Keep lookup and storage on the same explicit Cache API key. `MAGENTO_CACHE_NAMESPACE` separates activations through `__fpc`. Managed deployments require the Magento origin readiness header before storing a response.
- Validate origin responses before storing HTML. Vary changes/deletion must not populate the old context's entry; preserve origin cookies on MISS/pass and remove them from stored copies/HIT.
- Origin fetches use `cache: 'no-store'`; require compatibility date 2024-11-11 or later, or `cache_option_enabled`. Do not combine this option with `cf.cacheTtl`.
- Cache API storage is local to each data center. Magento passes Worker bindings on upload, including the global FPC TTL.
- Change the global cache application through `Model/Worker/CacheEngine.php`. A successful write to `env.php` also updates the Magento `system` configuration hash before reinitializing configuration. Preserve the current engine until deployment and selected routes succeed.
- Keep sending tag purges to deselected zones while the Worker still serves their routes. Verify a zone was deleted before pruning its tracking and queued purges.
- Stored `Cache-Tag` values must preserve Magento tags and the hostname tag format from `CacheConfig::getSiteTag()` so PHP purge requests match Worker entries.
- Verify deployed headers, login/logout context separation, and purge behavior in the target environment; local mocks alone do not establish Cloudflare behavior.

## Security

- Cloudflare zone/token settings are credentials: keep them out of commits, logs, and user-facing debug output.
