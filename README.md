# Magento 2 Cloudflare Integration

Use Cloudflare as your Magento 2 full-page cache and image optimization CDN.

[![Latest Version](https://img.shields.io/packagist/v/studioraz/magento2-cloudflare.svg)](https://packagist.org/packages/studioraz/magento2-cloudflare)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENCE)

---

## Table of Contents

1. [Overview](#overview)
2. [Features](#features)
3. [Prerequisites](#prerequisites)
4. [Installation](#installation)
5. [Configuration](#configuration)
    - [Cloudflare Images](#cloudflare-images)
    - [Full Page Cache Settings](#cloudflare-cache)
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

The module lets store administrators deploy and manage Cloudflare full-page caching from Magento Admin. Cloudflare serves cached storefront pages closer to shoppers, and Magento clears affected pages when store content changes. The module can also serve resized and optimized storefront images through Cloudflare.

Developed and maintained by [Studio Raz](https://studioraz.co.il/) and released as open-source for the Magento community.

---

## Features

### Full-Page Cache

- Test API credentials and deploy one Worker to selected zones from Magento Admin.
- Switch to Cloudflare CDN after a successful deployment and return to a configured Varnish installation after deleting the Worker.
- Purge affected pages by tag when store content changes.
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
- [Cloudflare Workers](https://workers.cloudflare.com/) for full-page caching.
- [Cloudflare image transformations](https://developers.cloudflare.com/images/) enabled for image optimization.
- A manually created Cloudflare Account API Token or User API Token with Workers Scripts Edit on the account and Zone Read, Workers Routes Edit, and Cache Purge on its zones.

### Magento

| Requirement | Version |
|---|---|
| PHP | `^8.1` |
| Magento 2 / `magento/framework` | `^103.0` |

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

Magento Admin exposes both sections under **Stores > Configuration > Studio Raz**.

### Cloudflare Images

The **General** group is available at the Default, Website, and Store View scopes.

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

### Cloudflare Cache

*Admin panel — Stores → Configuration → Studio Raz → Cloudflare Cache → Full Page Cache*

Set credentials, the Worker name, zone selection, and Worker routes in **Default Config**. Debug and Purge Configuration are also available at the Website scope.

| Field | Default | Description |
|---|---|---|
| Account ID | Not set | Cloudflare Account ID from the dashboard. |
| API Token | Not set | Cloudflare account or user token for deployment, routes, and purge. Stored encrypted. |
| Worker Name | `magento-fpc` | Name of the shared Worker. Choose it before the first deployment. |
| Worker Management | All accessible zones | Lists zones discovered with the saved credentials and provides the deployment controls. |
| Debug | No | Enables module diagnostics in `var/log/srcloudflarecache.log`. Credentials are excluded. |

**Test Credentials** loads the zone IDs and domains and checks Cache Purge permissions for the saved selection. **Select All** is enabled by default and includes newly discovered zones. Save changes to the account, token, or zone selection and test the credentials before deploying to unverified zones.

**Start Deploy** switches the global caching application to **Cloudflare CDN** in `app/etc/env.php` after the Worker deployment and all selected routes succeed. **Delete Worker** switches from **Cloudflare CDN** to **Varnish** after Cloudflare confirms the Worker is absent. The switch requires permission to edit Magento System configuration and a writable `app/etc/env.php`. Varnish needs to be configured in the target environment before switching back.

After either switch, Magento updates its system configuration hash automatically. If Magento reports existing unsynchronized configuration changes, run the configuration import before retrying.

While Cloudflare CDN is active, Magento keeps purging deselected zones until route cleanup confirms that their routes no longer invoke the Worker. When the last zone is cleared, **Start Deploy** switches to Varnish after successful route cleanup. **Active deployment** shows the version ID and deployment time whenever the page opens and after a Worker operation.

If **Test Credentials** reports an unavailable Zone ID, verify in Cloudflare that the zone was deleted. After confirmation, use **Forget deleted zone** on that row to remove its saved selection, route records, and purge jobs from Magento. The shared Worker keeps serving the other zones. If the zone still exists, restore the token's access to it.

## How It Works

Cloudflare serves cached storefront pages and sends cache misses to Magento. Magento tags cacheable responses and uses those tags to clear affected pages when store content changes. The Worker passes through private pages such as checkout and customer account pages. The separate image optimization feature lets Cloudflare serve storefront images in a size and format appropriate for the shopper's browser.

---

## Deploying the Cloudflare Worker

The module includes a Cloudflare Worker script (`CFWorker/FPC-worker.js`) that must be deployed to your Cloudflare account for the FPC feature to work.

### Deployment Steps

1. Create an account or user API token in Cloudflare with the required permissions. Save it with the **Account ID** under **Cloudflare Cache > Full Page Cache** in Default Config.
2. Click **Test Credentials** to verify access and inspect the zones. To deploy to a subset, save the selection and run the test again.
3. Set the **Worker Configuration** values, add any extra exclusions under **Worker Routes**, and save the configuration.
4. Click **Start Deploy** to configure the Worker and activate Cloudflare CDN. Check the result for each zone and review the active version and current cache engine.

### Worker Environment Variables

Magento supplies these bindings during deployment:

| Variable | Type | Description |
|---|---|---|
| `DEBUG` | Boolean | Enables diagnostic `X-FPC-*` response headers for debugging. |
| `DEFAULT_TTL` | Number | Fallback TTL for cacheable responses, taken from Magento's global **Full Page Cache > TTL for public content** (`system/full_page_cache/ttl`). |
| `HFP_TTL` | Number | TTL in seconds for hit-for-pass markers. Default: `120`. |
| `BYPASS_PATHS` | String | Additional Bypass Paths from Worker Configuration. Defaults to an empty string. The Worker receives matching requests and skips caching. |

The Worker strips 50+ common marketing and tracking query parameters to improve cache hit rates. See the `FILTER_GET` constant in `CFWorker/FPC-worker.js` for the complete list.

**Default bypass paths:** `/customer`, `/checkout`, `/catalogsearch`

### Worker Routes

In Magento Admin under **Cloudflare Cache → Worker Routes** in **Default Config**, review the read-only routes for static files, media, `/cdn-cgi/*`, `/rest/*`, and the automatically detected Magento Admin URL. Under **Additional Bypass Routes**, click **Add Route** and enter one path per row, such as `/api` or `/feeds`. Use **Delete** to remove a row. A path like `/feeds` produces `/feeds/*` exclusions for the domain and subdomains of each selected zone. Magento uses this field to synchronize Cloudflare routes during deployment.

Save the configuration and run **Start Deploy**. Magento uses Cloudflare routes without a Worker script to exclude matching requests from Worker execution. [Cloudflare Workers Routes](https://developers.cloudflare.com/workers/configuration/routing/routes/#matching-behavior)

When the configured Magento Admin host belongs to a selected zone, Magento prepares its bypass route before uploading the Worker. The route matches the Admin path on a shared host or the entire host for a dedicated Admin subdomain. A route configuration error stops the upload.

If a previous deployment created routes from **Additional Bypass Paths**, add any routes you want to keep to **Additional Bypass Routes**. The next deployment removes obsolete Magento-managed routes.

<img width="1722" height="792" alt="Screenshot 2026-04-02 at 00 07 42" src="https://github.com/user-attachments/assets/7cd66c65-baf0-43f4-b710-394460d15ee4" />

The Worker also has internal cache-bypass rules for requests it receives.

### Magento home page speed

<img width="740" height="340" alt="Screenshot 2026-04-01 at 23 08 31" src="https://github.com/user-attachments/assets/31198bef-d206-452e-a00e-de275caf52eb" />

---

## Automatic Cache Purging

The module sends the same Magento cache tags to the selected zones and to deselected zones whose routes still invoke the Worker. Each zone purges matching content and has its own result and retry state in the queue.

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

- Use **Test Credentials** to verify the account and zones. Make sure the API token has **Cache Purge** permission on each zone.
- Confirm that Cloudflare is selected as the caching application in **Stores > Configuration > Advanced > System > Full Page Cache > Caching Application**.
- Enable **Cache > Debug** and inspect `var/log/srcloudflarecache.log` for API errors.

**Pages are always served from origin (no CDN HIT)**

- Confirm the Cloudflare Worker is deployed and routed to your domain.
- Check that `Cache-Tag` headers are present in origin responses (visible in browser DevTools or `curl -I`).
- Check `X-FPC-*` headers in the response (requires `DEBUG=true` in worker env).
<img width="669" height="432" alt="Screenshot 2026-04-01 at 23 09 18" src="https://github.com/user-attachments/assets/2fec62e5-982a-4ee1-87a3-0c653a7c7cb6" />

**Worker debug headers missing**

- Enable **Worker Configuration → Worker Debug Mode**, save the configuration, and run **Start Deploy**.

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

This project is licensed under the [MIT License](LICENCE).
