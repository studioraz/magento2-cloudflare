# Magento 2 Cloudflare Integration

Use Cloudflare as your Magento 2 full-page cache and image optimization CDN.

[![Latest Version](https://img.shields.io/packagist/v/studioraz/magento2-cloudflare.svg)](https://packagist.org/packages/studioraz/magento2-cloudflare)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

---

## Table of Contents

1. [Overview](#overview)
2. [Features](#features)
3. [Prerequisites](#prerequisites)
4. [Installation](#installation)
5. [Configuration](#configuration)
    - [General Settings (Image Optimization)](#general-settings-image-optimization)
    - [Cache (FPC) Settings](#cache-fpc-settings)
6. [How It Works](#how-it-works)
7. [Deploying the Cloudflare Worker](#deploying-the-cloudflare-worker)
    - [Deployment Steps](#deployment-steps)
    - [Worker Environment Variables](#worker-environment-variables)
8. [Automatic Cache Purging](#automatic-cache-purging)
9. [Image Optimization](#image-optimization)
10. [Troubleshooting](#troubleshooting)
11. [Contributing](#contributing)
12. [License](#license)

---

## Overview

Magento's built-in full-page cache and Varnish setups require significant server-side infrastructure to manage, scale, and keep in sync with content changes. Image optimization typically adds another layer of complexity with local cache directories and third-party services.

This module replaces all of that with [Cloudflare](https://www.cloudflare.com/):

1. **Full-Page Cache (FPC)** -- use Cloudflare's global CDN as a drop-in replacement for Varnish or the built-in FPC. A bundled Cloudflare Worker handles caching logic at the edge, replicating Magento's Varnish behavior without requiring you to manage Varnish servers.

2. **Image Optimization** -- automatically rewrite image URLs to Cloudflare's `/cdn-cgi/image/` endpoint. Images are resized, converted to modern formats (WebP, AVIF), and served from the edge -- no local image cache needed.

Developed and maintained by [Studio Raz](https://studioraz.co.il/) and released as open-source for the Magento community.

---

## Features

### Full-Page Cache

- Use Cloudflare as a selectable caching application alongside Built-in and Varnish
- Smart cache invalidation -- only affected pages are purged when content changes, not the entire cache
- Automatic cleanup of 50+ marketing/tracking query parameters (UTM, Facebook, Google Ads, etc.) to improve cache hit rates
- GraphQL cache support with proper cache key handling
- Configurable TTL, bypass paths, and debug headers
- Hit-for-pass mechanism prevents cache pollution from uncacheable pages

### Image Optimization

- Automatic image URL rewriting to Cloudflare's image resizing pipeline
- Serves images in the best format the browser supports (WebP, AVIF, or original)
- Configurable quality (1--100) and fit mode per store view
- Covers product images, CMS content, logos, and widget images
- Eliminates local image cache generation, reducing disk usage and deployment size
- Debug logging available for API troubleshooting

---

## Prerequisites

### Cloudflare

- A Cloudflare account with your domain proxied through Cloudflare (orange cloud icon enabled)
- **For FPC:** A [Cloudflare Workers](https://workers.cloudflare.com/) subscription. The free tier allows 100,000 requests/day; the paid plan ($5/month) includes 10 million requests/month.
- **For Image Optimization:** [Cloudflare Images](https://developers.cloudflare.com/images/) is available on all plans. The free tier includes 5,000 unique transformations/month. For higher volumes, the paid plan costs $0.50 per 1,000 unique transformations.
- A Cloudflare **API Token** with the **Zone.Cache Purge** permission

### Magento

| Requirement | Version |
|---|---|
| PHP | `^8.1` |
| Magento 2 / `magento/framework` | `~103.0` |

---

## Installation

1. Require the module via Composer:
   ```bash
   composer require studioraz/magento2-cloudflare
   ```

2. Enable the module and run setup:
   ```bash
   bin/magento module:enable SR_Cloudflare
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:clean
   ```

---

## Configuration

All settings are available in the Magento Admin under:

**Stores > Configuration > Studio Raz > Cloudflare**

### General Settings (Image Optimization)

| Field | Default | Description |
|---|---|---|
| Enabled | No | Enable/disable Cloudflare image optimization for the store view. |
| Image Quality | `85` | JPEG/WebP/AVIF quality (1--100). |
| Image Fit | `none` | How image dimensions are interpreted. See [Cloudflare fit documentation](https://developers.cloudflare.com/images/image-resizing/url-format/#fit). |

**Image Fit options:**

| Value | Description |
|---|---|
| `none` | No fit constraint applied (parameter omitted). |
| `contain` | Scale down to fit within width x height, preserving aspect ratio. |
| `cover` | Resize to fill width x height, cropping if necessary. |
| `crop` | Crop to exact width x height. |
| `pad` | Resize to fit within bounds and pad remaining space. |
| `scale-down` | Like `contain`, but never scales up. |

### Cache (FPC) Settings

These settings are scoped to **Default / Website** (not Store View).

| Field | Default | Description |
|---|---|---|
| Enabled | No | Enable Cloudflare FPC cache management. |
| Zone ID | -- | Cloudflare Zone ID from the dashboard Overview page. |
| Account ID | -- | Cloudflare Account ID from the dashboard Overview page. |
| API Token | -- | Cloudflare API Token with **Zone.Cache Purge** permission. Stored encrypted. |
| Debug | No | When enabled, logs API requests and responses to `var/log/srcloudflarecache.log`. |

<img width="1246" height="804" alt="Screenshot 2026-04-01 at 23 53 08" src="https://github.com/user-attachments/assets/f9df2e3f-adbb-43f2-b2b7-c5536f57d79f" />

> **Important:** You must also select Cloudflare as the FPC application. Go to **Stores > Configuration > Advanced > System > Full Page Cache > Caching Application** and select **Cloudflare**.

## How It Works

### Full-Page Cache Flow

When Cloudflare is selected as the caching application, the module adds cache tags to every page response. A Cloudflare Worker (deployed on your Cloudflare account) intercepts incoming requests before they reach your server and handles caching decisions at the edge -- just like Varnish would, but without any server-side infrastructure.

```
Browser ──> Cloudflare CDN ──> Worker (cache check) ──> Origin (Magento)
                                                     <── Response + Cache-Tag headers
        <── Cached response (on subsequent requests)
```

The Worker automatically bypasses the cache for admin pages, checkout, customer account pages, and API requests. It also strips marketing query parameters (UTM, Facebook, Google Ads, etc.) so that `?utm_source=...` variants don't create duplicate cache entries.

### Automatic Cache Purging

When content changes in Magento -- saving a product, updating a category, flushing cache from the admin -- the module calls the Cloudflare API to purge only the affected pages by their cache tags. Unrelated cached pages remain warm.

### Image Optimization Flow

When image optimization is enabled, the module rewrites image URLs to route through Cloudflare's `/cdn-cgi/image/` endpoint. Cloudflare automatically resizes, compresses, and converts images to the best format the browser supports -- all at the edge, with no processing on your server.

---

## Deploying the Cloudflare Worker

The module includes a Cloudflare Worker script (`CFWorker/FPC-worker.js`) that must be deployed to your Cloudflare account for the FPC feature to work.

### Deployment Steps

1. Log in to the [Cloudflare dashboard](https://dash.cloudflare.com/).
2. Navigate to **Workers & Pages > Create Application > Create Worker**.
3. Paste or upload the contents of `CFWorker/FPC-worker.js`.
4. Set up **Environment Variables** if needed (see below).
5. Add a **Route** that maps your Magento store domain to this worker (e.g., `example.com/*`).

### Worker Environment Variables

Set these in the Cloudflare Worker **Settings > Variables** panel:

| Variable | Type | Description |
|---|---|---|
| `DEBUG` | Boolean | Enables diagnostic `X-FPC-*` response headers for debugging. |
| `DEFAULT_TTL` | Number | Cache TTL in seconds for successful responses. Falls back to Magento's global FPC TTL. |
| `HFP_TTL` | Number | TTL in seconds for hit-for-pass markers. Default: `120`. |
| `ADMIN_PATH` | String | Admin URL segment to bypass. Default: `admin`. |
| `BYPASS_PATHS` | String | Comma-separated additional paths to bypass (e.g. `/api,/rest`). |

The Worker strips 50+ common marketing and tracking query parameters to improve cache hit rates. See the `FILTER_GET` constant in `CFWorker/FPC-worker.js` for the complete list.

**Default bypass paths:** `/customer`, `/checkout`, `/catalogsearch`

### Worker Routes

**Worker Routes** allow you to define URL patterns that should bypass the Cloudflare Worker entirely. By excluding specific URLs from Worker processing, you reduce the number of requests handled by the Worker, which helps lower costs and avoid unnecessary overhead for routes that don't need caching (e.g., admin panels, API endpoints, or checkout pages).

<img width="1722" height="792" alt="Screenshot 2026-04-02 at 00 07 42" src="https://github.com/user-attachments/assets/7cd66c65-baf0-43f4-b710-394460d15ee4" />

> **Note:** The worker itself already has all the basic bypassed urls configuration, in case of this setup it will be as a fallback.

### Magento home page speed

<img width="740" height="340" alt="Screenshot 2026-04-01 at 23 08 31" src="https://github.com/user-attachments/assets/31198bef-d206-452e-a00e-de275caf52eb" />

---

## Automatic Cache Purging

The module automatically purges the Cloudflare cache when content changes in Magento. Only the affected pages are purged -- the rest of your cache stays warm.

### What triggers a cache purge

- Saving a product or category
- Mass product attribute or status updates
- Applying catalog price rules
- Changing currency rates or symbols
- Saving system configuration
- Changing theme assignments
- Reindex operations

### What triggers a full cache flush

- Clicking "Flush Magento Cache" or "Flush Cache Storage" in Cache Management
- Cleaning the media or catalog image cache
- Refreshing a specific cache type

> **Note:** Even a "full" flush only purges cached pages for your site. It preserves the Cloudflare Image Transformations cache, so your optimized images remain available without re-processing.

---

## Image Optimization

When image optimization is enabled (**General > Enabled = Yes**), the module rewrites image URLs from their standard Magento paths to the Cloudflare image transformations endpoint:

```
https://example.com/cdn-cgi/image/format=auto,metadata=none,quality=85,width=300,height=300/media/catalog/product/image.jpg
```

### Supported image types

- Product catalog images (thumbnails, listings, gallery)
- Product media gallery URLs
- CMS page and block images
- Store logo
- Widget images
- Mirasvit catalog label badges (if [Mirasvit CatalogLabel](https://mirasvit.com/) is installed)

When enabled, Magento's local image cache generation is skipped entirely. Images are served directly from Cloudflare, eliminating the `/pub/media/catalog/product/cache/` directory and reducing disk usage.

---

## Troubleshooting

**Images are not being resized / URLs are not rewritten**

- Ensure **General > Enabled** is set to **Yes** for the applicable scope (Store View).
- Check that your Cloudflare zone has [Image Transformations](https://developers.cloudflare.com/images/) enabled.
- Verify the image URL contains `/cdn-cgi/image/` -- if it does not, check whether another plugin or cache is serving a stale URL.

**Cache is not being purged after product/category save**

- Ensure **Cache > Enabled** is set to **Yes**.
- Ensure **Zone ID** and **API Token** are correctly set and that the API Token has **Zone.Cache Purge** permission.
- Confirm that Cloudflare is selected as the caching application in **Stores > Configuration > Advanced > System > Full Page Cache > Caching Application**.
- Enable **Cache > Debug** and inspect `var/log/srcloudflarecache.log` for API errors.

**Pages are always served from origin (no CDN HIT)**

- Confirm the Cloudflare Worker is deployed and routed to your domain.
- Check that `Cache-Tag` headers are present in origin responses (visible in browser DevTools or `curl -I`).
- Ensure the worker's `ADMIN_PATH` variable matches your actual admin path if it is customized.
- Check `X-FPC-*` headers in the response (requires `DEBUG=true` in worker env).
<img width="669" height="432" alt="Screenshot 2026-04-01 at 23 09 18" src="https://github.com/user-attachments/assets/2fec62e5-982a-4ee1-87a3-0c653a7c7cb6" />

**Worker debug headers missing**

-  Set the `DEBUG` environment variable to `true` directly in the Cloudflare Worker settings.

**Images are served in original format instead of WebP/AVIF**

- Verify that your Cloudflare zone has [Image Transformations](https://developers.cloudflare.com/images/) enabled.
- Check the browser's `Accept` header includes `image/webp` or `image/avif`.
- Confirm the image URL uses the `/cdn-cgi/image/format=auto,...` format -- the `format=auto` parameter is what enables format negotiation.

---

## Contributing

Contributions are welcome! Please feel free to submit issues and pull requests.

1. Fork the repository
2. Create your feature branch (`git checkout -b (feature/bugfix)/my-feature`)
3. Commit your changes
4. Push to the branch (`git push origin feature/my-feature`)
5. Open a Pull Request

Please follow the [Magento 2 coding standards](https://developer.adobe.com/commerce/php/coding-standards/).

---

## License

This project is licensed under the [MIT License](LICENSE).
