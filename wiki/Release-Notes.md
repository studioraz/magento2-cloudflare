# Release Notes

All notable releases of `studioraz/magento2-cloudflare` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) conventions,
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] — Initial Release

### Added

- **Cloudflare FPC integration** — registers Cloudflare as a selectable Magento Full Page Cache application (alongside Built-in and Varnish).
- **Tag-based cache purge** — `PurgeByTags` observer purges Cloudflare edge cache by Magento cache tags when products, categories, CMS pages, or configuration are saved.
- **Site-wide cache flush** — `FlushAllCacheObserver` purges all cached pages for the current website (via hostname-derived tag) on flush events, without clearing the Cloudflare Image Transformations cache.
- **Cache-Tag header injection** — `AddCacheTagHeader` plugin copies `X-Magento-Tags` into `Cache-Tag` (with the site hostname tag prepended) so Cloudflare can index and purge by tag.
- **Cache-Control header management** — `LayoutPlugin` sets public `Cache-Control` headers and collects block identities into `X-Magento-Tags` for cacheable pages.
- **Cloudflare FPC Worker** (`CFWorker/FPC-worker.js`) — Cloudflare Worker script that mirrors Magento's Varnish VCL behavior: cookie handling, marketing query-parameter stripping, bypass rules, hit-for-pass markers, and optional debug headers.
- **Cloudflare Image Resizing** — rewrites product, catalog, CMS, logo, and widget image URLs to the `/cdn-cgi/image/` endpoint for on-the-fly format conversion (WebP/AVIF), quality control, and dimension-based resizing.
- **Configurable image quality and fit** — store-view-scoped settings for image quality (1–100, default 85) and fit mode (none / contain / cover / crop / pad / scale-down).
- **Local image cache bypass** — `IsCachedPlugin` prevents Magento from generating local resized image files in `/pub/media/catalog/product/cache/` when the module is active.
- **Worker environment variable configuration** — admin fields for `DEBUG`, `DEFAULT_TTL`, `HFP_TTL`, `ADMIN_PATH`, and `BYPASS_PATHS` worker variables.
- **Debug logging** — optional logging of Cloudflare API requests and responses to `var/log/srcloudflarecache.log`.
- **Mirasvit CatalogLabel compatibility** — image URL rewriting for Mirasvit label badge images.

[1.0.0]: https://github.com/studioraz/magento2-cloudflare/releases/tag/1.0.0
