# SR\_Cloudflare — Magento 2 Cloudflare Integration

**Module:** `SR_Cloudflare`  
**Package:** `studioraz/magento2-cloudflare`  
**Vendor:** [Studio Raz](https://www.studio-raz.com)  
**Support:** support@studioraz.co.il

---

## Table of Contents

1. [Overview](#overview)
2. [Features](#features)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [Configuration](#configuration)
   - [General Settings (Image Resizing)](#general-settings-image-resizing)
   - [Cache (FPC) Settings](#cache-fpc-settings)
   - [Worker Configuration](#worker-configuration)
6. [Cloudflare Worker (FPC Worker)](#cloudflare-worker-fpc-worker)
   - [How It Works](#how-it-works)
   - [Deploying the Worker](#deploying-the-worker)
   - [Worker Environment Variables](#worker-environment-variables)
7. [Cache Invalidation](#cache-invalidation)
8. [Image Resizing via Cloudflare](#image-resizing-via-cloudflare)
9. [Developer Reference](#developer-reference)
   - [Module Structure](#module-structure)
   - [Key Classes](#key-classes)
   - [Events Observed](#events-observed)
   - [Plugins Registered](#plugins-registered)
   - [ACL Resources](#acl-resources)
10. [Troubleshooting](#troubleshooting)

---

## Overview

`SR_Cloudflare` integrates Magento 2 with [Cloudflare](https://www.cloudflare.com/) to provide two complementary capabilities:

1. **Full-Page Cache (FPC) via Cloudflare CDN** — replaces the default Varnish/built-in FPC with Cloudflare's edge cache, managed by a Cloudflare Worker that mirrors Magento's Varnish VCL logic.
2. **On-the-fly Image Resizing via Cloudflare Images** — rewrites product and CMS image URLs to the `/cdn-cgi/image/` endpoint, enabling automatic format conversion (WebP/AVIF), quality control, and dimension-based resizing at the CDN edge.

The module is developed and maintained by [Studio Raz](https://www.studio-raz.com) and is a proprietary extension.

---

## Features

- **Cloudflare as Magento FPC application** — selectable as a caching application alongside Built-in and Varnish options.
- **Tag-based cache purge** — Cloudflare cache is purged granularly by Magento cache tags (e.g., `cat_p_1`, `cms_p_2`) rather than purging everything, preserving the Cloudflare Images cache.
- **Site-wide cache flush** — flushes all cached pages for the current site using a hostname-derived tag, without clearing the global Cloudflare Image Transformations cache.
- **Cloudflare FPC Worker** — a Cloudflare Worker script (`CFWorker/FPC-worker.js`) that mirrors Magento's Varnish VCL behavior at the edge: cookie handling, bypass rules, hit-for-pass, cache tag injection.
- **Image URL rewriting** — rewrites URLs for product images, catalog images, CMS media, logo, and widget images to use Cloudflare's `/cdn-cgi/image/` resizing pipeline.
- **Format auto-negotiation** — serves images in the best format the browser supports (WebP, AVIF, or original).
- **Configurable image quality and fit mode** — quality (1–100, default 85) and fit mode (none, contain, cover, crop, pad, scale-down) are configurable per store view.
- **Local image cache bypass** — when the module is active, Magento's local product image cache generation is skipped, since images are served directly from Cloudflare Images.
- **Debug logging** — Cloudflare API requests and responses can be logged to `var/log/srcloudflarecache.log` for troubleshooting.

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `~7.4` or `^8.0` |
| `magento/framework` | `~103.0` |
| `studioraz/magento2-base` | `~1.3` |

---

## Installation

1. Configure authentication for Studio Raz's private Composer repository:
   ```bash
   composer config --auth http-basic.repo.packagist.com <username> <password>
   ```

2. Add the private repository and disable the public Packagist mirror:
   ```bash
   composer config repositories.private-packagist composer https://repo.packagist.com/studioraz/
   composer config repositories.packagist.org false
   ```

3. Require the module:
   ```bash
   composer require studioraz/magento2-cloudflare
   ```

4. Enable the module and run setup:
   ```bash
   bin/magento module:enable SR_Cloudflare
   bin/magento setup:upgrade
   bin/magento cache:flush
   ```

---

## Configuration

All settings are available in the Magento Admin under:

**Stores → Configuration → Studio Raz → Cloudflare**

### General Settings (Image Resizing)

| Field | Path | Default | Description |
|---|---|---|---|
| Enabled | `srcloudflare/general/active` | `0` (No) | Enable/disable Cloudflare image resizing for the store. |
| Image Quality | `srcloudflare/general/image_quality` | `85` | JPEG/WebP/AVIF quality (1–100). PNG values enable PNG8 palette. |
| Image Fit | `srcloudflare/general/image_fit` | `none` | How image dimensions are interpreted. See [Cloudflare fit documentation](https://developers.cloudflare.com/images/image-resizing/url-format/#fit). |

**Image Fit options:**

| Value | Description |
|---|---|
| `none` | No fit constraint applied (parameter omitted). |
| `contain` | Scale down to fit within width × height, preserving aspect ratio. |
| `cover` | Resize to fill width × height, cropping if necessary. |
| `crop` | Crop to exact width × height. |
| `pad` | Resize to fit within bounds and pad remaining space. |
| `scale-down` | Like `contain`, but never scales up. |

### Cache (FPC) Settings

These settings are scoped to **Default / Website** (not Store View).

| Field | Path | Default | Description |
|---|---|---|---|
| Enabled | `srcloudflare/cache/active` | `0` (No) | Enable Cloudflare FPC cache management. |
| Zone ID | `srcloudflare/cache/zone_id` | — | Cloudflare Zone ID from the dashboard Overview page. Required when Cache is enabled. |
| Account ID | `srcloudflare/cache/account_id` | — | Cloudflare Account ID from the dashboard Overview page. Required when Cache is enabled. |
| API Token | `srcloudflare/cache/api_token` | — | Cloudflare API Token with **Zone.Cache Purge** permission. Stored encrypted. Required when Cache is enabled. |
| Debug | `srcloudflare/cache/debug` | `0` (No) | When enabled, logs API requests and responses to `var/log/srcloudflarecache.log`. |

> **Note:** Cloudflare must also be selected as the FPC application. Go to **Stores → Configuration → Advanced → System → Full Page Cache → Caching Application** and select **Cloudflare**.

### Worker Configuration

These settings configure the environment variables that the Cloudflare FPC Worker uses. They are scoped to **Default / Website**.

| Field | Config Path | Env Variable | Default | Description |
|---|---|---|---|---|
| Debug Mode | `srcloudflare/worker/debug` | `DEBUG` | `0` (No) | When enabled, the worker adds diagnostic response headers (`X-FPC-*`). |
| Default TTL Override (seconds) | `srcloudflare/worker/default_ttl` | `DEFAULT_TTL` | *(global FPC TTL)* | Per-website TTL override. Leave empty to use **System → Full Page Cache → TTL for public content**. |
| Hit-For-Pass TTL (seconds) | `srcloudflare/worker/hfp_ttl` | `HFP_TTL` | `120` | TTL for hit-for-pass markers (placeholders for uncacheable URLs). |
| Admin Path | `srcloudflare/worker/admin_path` | `ADMIN_PATH` | `admin` | The admin URL segment used to bypass caching. Update if your admin path does not contain "admin". |
| Bypass Paths | `srcloudflare/worker/bypass_paths` | `BYPASS_PATHS` | — | Comma-separated additional URL path segments to bypass caching (e.g. `/api,/rest`). |

---

## Cloudflare Worker (FPC Worker)

The file `CFWorker/FPC-worker.js` is an ES Module Cloudflare Worker that mirrors Magento 2's Varnish VCL behavior at the Cloudflare edge.

### How It Works

| VCL Phase | Worker Function | Description |
|---|---|---|
| `vcl_recv` | `vclRecv()` | Strips tracking query parameters, checks bypass rules (admin, checkout, customer, REST/GraphQL, etc.), normalises cookies. |
| `vcl_hash` | `vclHash()` | Builds the cache key from the request URL (after query string normalisation). |
| `vcl_backend_response` | `vclBackendResponse()` | Checks `X-Magento-Cache-Control: no-cache` to decide whether to cache or set a hit-for-pass marker. |
| `vcl_deliver` | `vclDeliver()` | Optionally adds `X-FPC-*` debug headers to the response. |

**Cache strategy:**
- Uses `fetch()` with `cacheEverything: true` and `cacheTtlByStatus` to force CDN caching even when Magento sends `Set-Cookie` headers. Cloudflare automatically strips `Set-Cookie` from cached copies.
- `Cache-Tag` headers (injected by the `AddCacheTagHeader` plugin) are indexed through the CDN pipeline to enable granular purge-by-tag via the Cloudflare API.
- Hit-for-pass markers are stored in cache with a short TTL to avoid repeatedly fetching uncacheable pages from origin.

**Marketing/tracking query parameters stripped** (mirrors VCL `FILTER_GET`):  
`fbclid`, `utm_*`, `gclid`, `msclkid`, `_ga`, `_gl`, and many others. See the `FILTER_GET` constant in `CFWorker/FPC-worker.js` for the complete list.

**Default bypass paths** (mirrors VCL pass rules):  
`/customer`, `/checkout`, `/catalogsearch`

### Deploying the Worker

1. Log in to the [Cloudflare dashboard](https://dash.cloudflare.com/).
2. Navigate to **Workers & Pages → Create Application → Create Worker**.
3. Paste or upload the contents of `CFWorker/FPC-worker.js`.
4. Set the required **Environment Variables** (see [Worker Environment Variables](#worker-environment-variables)).
5. Add a **Route** that maps your Magento store domain to this worker (e.g., `example.com/*`).

### Worker Environment Variables

Set these in the Cloudflare Worker **Settings → Variables** panel, or manage them via the Magento admin (values are synced via the Cloudflare API when saved).

| Variable | Type | Description |
|---|---|---|
| `DEBUG` | Boolean | Enables diagnostic `X-FPC-*` response headers. |
| `DEFAULT_TTL` | Number | Cache TTL in seconds for `200–299` responses. Falls back to Magento's global FPC TTL. |
| `HFP_TTL` | Number | TTL in seconds for hit-for-pass markers. Default: `120`. |
| `ADMIN_PATH` | String | Admin URL segment to bypass. Default: `admin`. |
| `BYPASS_PATHS` | String | Comma-separated additional paths to bypass (e.g. `/api,/rest`). |

---

## Cache Invalidation

The module hooks into Magento's cache management events to automatically purge the Cloudflare edge cache when content changes.

### Tag-Based Purge

The `PurgeByTags` observer triggers on the following events and calls the Cloudflare API to purge pages by their cache tags (up to 30 tags per API request):

| Event | Description |
|---|---|
| `clean_cache_by_tags` | Generic cache tag cleanup (product/category saves, etc.). |
| `assigned_theme_changed` | Theme assignment changed. |
| `catalogrule_after_apply` | Catalog price rule applied. |
| `controller_action_postdispatch_adminhtml_system_currency_saveRates` | Currency rates saved. |
| `controller_action_postdispatch_adminhtml_system_config_save` | System configuration saved. |
| `controller_action_postdispatch_adminhtml_catalog_product_action_attribute_save` | Mass product attribute update. |
| `controller_action_postdispatch_adminhtml_catalog_product_massStatus` | Mass product status change. |
| `controller_action_postdispatch_adminhtml_system_currencysymbol_save` | Currency symbol saved. |
| `clean_cache_after_reindex` | Cache cleaned after a reindex operation. |

### Full-Cache Flush

The `FlushAllCacheObserver` purges all pages for the current website (by the hostname-derived site tag) on the following events:

| Event | Description |
|---|---|
| `adminhtml_cache_flush_system` | "Flush Magento Cache" button in Cache Management. |
| `adminhtml_cache_flush_all` | "Flush Cache Storage" button in Cache Management. |
| `clean_media_cache_after` | Media cache cleaned. |
| `clean_catalog_images_cache_after` | Catalog image cache cleaned. |
| `adminhtml_cache_refresh_type` | A specific cache type refreshed. |
| `assign_theme_to_stores_after` | Theme assigned to stores. |

> **Note:** Full-cache flush uses a **tag-based purge** (hostname tag), not `purge_everything`. This deliberately preserves the Cloudflare Image Transformations cache, which is separate from page cache.

---

## Image Resizing via Cloudflare

When **General → Enabled** is set to **Yes**, the module rewrites image URLs from their standard Magento paths to the Cloudflare Image Resizing endpoint:

```
https://example.com/cdn-cgi/image/format=auto,metadata=none,quality=85,width=300,height=300/media/catalog/product/image.jpg
```

The following image sources are rewritten:

| Context | Plugin | Method Intercepted |
|---|---|---|
| Theme logo | `RebuildImageSrcUrlPlugin` | `Magento\Theme\Block\Html\Header\Logo::getLogoSrc` |
| CMS content images | `RebuildImageSrcUrlPlugin` | `Magento\Cms\Model\Template\Filter::mediaDirective` |
| Widget images | `RebuildImageSrcUrlPlugin` | `SR\Widgets\Block\Widget\Banners::getMedia` |
| Catalog product image asset | `Catalog\Asset\Image\RebuildImageSrcUrlPlugin` | `Magento\Catalog\Model\View\Asset\Image::getUrl` |
| Product media URL | `Catalog\Asset\Image\RebuildImageSrcUrlPlugin` | `Magento\Catalog\Model\Product\Media\Config::getMediaUrl` |
| Mirasvit label badge image | `Mirasvit\Asset\BadgeImage\RebuildImageSrcUrlPlugin` | `Mirasvit\CatalogLabel\Model\Label\Display` |

Additionally, `IsCachedPlugin` intercepts `Magento\Catalog\Model\Product\Image::isCached` and returns `true` when the module is active, preventing Magento from generating local image cache files in `/pub/media/catalog/product/cache/`.

---

## Developer Reference

### Module Structure

```
SR_Cloudflare/
├── CFWorker/
│   └── FPC-worker.js               # Cloudflare FPC Worker script
├── Config/
│   ├── CacheConfig.php             # FPC & Worker config getters
│   ├── Config.php                  # General (image resizing) config getters
│   └── ModuleState.php             # Active state helper (wraps Config)
├── Helper/
│   └── CloudflareUrlFormatHelper.php  # Builds /cdn-cgi/image/... URLs
├── Model/
│   ├── CloudflareClient.php        # Cloudflare purge API client
│   ├── Logger/
│   │   └── Handler/
│   │       └── CacheFileHandler.php  # Debug file logger handler
│   └── System/Config/Source/
│       ├── ApplicationPlugin.php   # Adds "Cloudflare" to FPC application dropdown
│       └── ImageFit.php            # ImageFit option source
├── Observer/
│   ├── FlushAllCacheObserver.php   # Purges all pages on full flush events
│   └── PurgeByTags.php             # Purges pages by cache tags on save events
├── Plugin/
│   ├── AddCacheTagHeader.php       # Injects Cache-Tag header from X-Magento-Tags
│   ├── RebuildImageSrcUrlPlugin.php  # Rewrites logo/CMS/widget image URLs
│   ├── App/FrontController/
│   │   └── CloudflarePlugin.php    # Sets cookie version header for FPC
│   ├── Catalog/
│   │   ├── Asset/Image/
│   │   │   └── RebuildImageSrcUrlPlugin.php  # Rewrites catalog image URLs
│   │   └── Product/Image/
│   │       └── IsCachedPlugin.php  # Skips local image cache generation
│   ├── Controller/Result/
│   │   └── CloudflarePlugin.php    # Sets cookie version header (result path)
│   ├── Layout/
│   │   └── LayoutPlugin.php        # Sets Cache-Control headers & X-Magento-Tags
│   ├── Mirasvit/Asset/BadgeImage/
│   │   └── RebuildImageSrcUrlPlugin.php  # Rewrites Mirasvit label images
│   └── Theme/Header/
│       └── GetLogoSrcPlugin.php    # Rewrites header logo URL
├── etc/
│   ├── acl.xml                     # ACL resource definitions
│   ├── config.xml                  # Default configuration values
│   ├── di.xml                      # Global DI configuration
│   ├── events.xml                  # Event observer registrations
│   ├── module.xml                  # Module declaration & dependencies
│   ├── adminhtml/
│   │   ├── di.xml                  # Admin-area DI (ApplicationPlugin)
│   │   ├── menu.xml                # Admin menu entries
│   │   └── system.xml              # System configuration fields
│   └── frontend/
│       └── di.xml                  # Frontend plugin registrations
└── registration.php                # Module registration
```

### Key Classes

| Class | Purpose |
|---|---|
| `SR\Cloudflare\Config\Config` | Reads `srcloudflare/general/*` store-scoped config values (active, image quality, image fit). |
| `SR\Cloudflare\Config\CacheConfig` | Reads `srcloudflare/cache/*` and `srcloudflare/worker/*` config values; checks if Cloudflare is the active FPC application and if FPC cache type is enabled. |
| `SR\Cloudflare\Config\ModuleState` | Wraps `Config::isActive()` with an optional `forceActive` override (useful for testing). |
| `SR\Cloudflare\Model\CloudflareClient` | Sends cache purge API requests to Cloudflare (`purgeByTags`, `purgeAll`, `purgeByUrls`). Batches tags in groups of 30. |
| `SR\Cloudflare\Helper\CloudflareUrlFormatHelper` | Constructs `/cdn-cgi/image/<params>/<path>` URLs from standard Magento image URLs. |
| `SR\Cloudflare\Plugin\AddCacheTagHeader` | Copies `X-Magento-Tags` into `Cache-Tag` header (with hostname site tag prepended) so Cloudflare can index and purge by tag. |
| `SR\Cloudflare\Plugin\Layout\LayoutPlugin` | Sets `Cache-Control: public, max-age=<ttl>` headers and collects block identities into `X-Magento-Tags`. |

### Events Observed

See [Cache Invalidation](#cache-invalidation) for the complete event list.

### Plugins Registered

**Frontend area** (`etc/frontend/di.xml`):

| Plugin Class | Target | Method(s) |
|---|---|---|
| `RebuildImageSrcUrlPlugin` | `Magento\Theme\Block\Html\Header\Logo` | `afterGetLogoSrc` |
| `RebuildImageSrcUrlPlugin` | `Magento\Cms\Model\Template\Filter` | `afterMediaDirective` |
| `RebuildImageSrcUrlPlugin` | `Magento\Widget\Model\Template\Filter` | `afterMediaDirective` |
| `RebuildImageSrcUrlPlugin` | `SR\Widgets\Block\Widget\Banners` | `afterGetMedia` |
| `Catalog\Asset\Image\RebuildImageSrcUrlPlugin` | `Magento\Catalog\Model\View\Asset\Image` | `afterGetUrl` |
| `Catalog\Asset\Image\RebuildImageSrcUrlPlugin` | `Magento\Catalog\Model\Product\Media\Config` | `afterGetMediaUrl` |
| `Catalog\Product\Image\IsCachedPlugin` | `Magento\Catalog\Model\Product\Image` | `aroundIsCached` |
| `Mirasvit\Asset\BadgeImage\RebuildImageSrcUrlPlugin` | `Mirasvit\CatalogLabel\Model\Label\Display` | *(label image)* |
| `AddCacheTagHeader` | `Magento\Framework\Controller\ResultInterface` | `afterRenderResult` (sortOrder=-10) |
| `Controller\Result\CloudflarePlugin` | `Magento\Framework\Controller\ResultInterface` | `afterRenderResult` (sortOrder=1) |
| `App\FrontController\CloudflarePlugin` | `Magento\Framework\App\FrontControllerInterface` | `afterDispatch` |
| `Layout\LayoutPlugin` | `Magento\Framework\View\Layout` | `afterGenerateElements`, `afterGetOutput` |

**Admin area** (`etc/adminhtml/di.xml`):

| Plugin Class | Target | Method(s) |
|---|---|---|
| `Model\System\Config\Source\ApplicationPlugin` | `Magento\PageCache\Model\System\Config\Source\Application` | Adds "Cloudflare" option to FPC application selector. |

### ACL Resources

| Resource ID | Title | Parent |
|---|---|---|
| `SR_Cloudflare::srcloudflare` | Cloudflare | `SR_Base::srbase` |
| `SR_Cloudflare::srcloudflare_settings` | Settings | `SR_Cloudflare::srcloudflare` |

---

## Troubleshooting

**Images are not being resized / URLs are not rewritten**

- Ensure **General → Enabled** is set to **Yes** for the applicable scope (Store View).
- Check that your Cloudflare zone has [Image Resizing](https://developers.cloudflare.com/images/image-resizing/) enabled.
- Verify the image URL contains `/cdn-cgi/image/` — if it does not, check whether another plugin or cache is serving a stale URL.

**Cache is not being purged after product/category save**

- Ensure **Cache → Enabled** is set to **Yes**.
- Ensure **Zone ID** and **API Token** are correctly set and that the API Token has **Zone.Cache Purge** permission.
- Confirm that Cloudflare is selected as the caching application in **Stores → Configuration → Advanced → System → Full Page Cache → Caching Application**.
- Enable **Cache → Debug** and inspect `var/log/srcloudflarecache.log` for API errors.

**Pages are always served from origin (no CDN HIT)**

- Confirm the Cloudflare Worker is deployed and routed to your domain.
- Check that `Cache-Tag` headers are present in origin responses (visible in browser DevTools or `curl -I`).
- Ensure the worker's `ADMIN_PATH` variable matches your actual admin path if it is customised.
- Check `X-FPC-*` headers in the response (requires `DEBUG=true` in worker env).

**Worker debug headers missing**

- Set **Worker Configuration → Debug Mode** to **Yes** in the Magento admin and save.
- Alternatively, set the `DEBUG` environment variable to `true` directly in the Cloudflare Worker settings.
