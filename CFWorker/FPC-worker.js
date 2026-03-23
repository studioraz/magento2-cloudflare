/**
 * Cloudflare FPC Worker — Exact Varnish Mirror
 *
 * Replicates Magento 2 Varnish VCL behavior (varnish7.vcl) using CF CDN cache.
 * No R2, no KV. Purge handled by SR_CloudflareCache PHP module via CF API.
 *
 * Caching strategy:
 *   fetch() with cacheEverything + cacheTtlByStatus — forces CDN caching even when
 *   origin sends Set-Cookie (Magento always sends PHPSESSID/form_key). CF strips
 *   Set-Cookie from the cached copy automatically. Cache-Tag headers are indexed
 *   through the CDN pipeline, enabling purge-by-tag via SR_CloudflareCache module.
 *   We do NOT use cache.put() for cacheable responses because it overwrites the
 *   fetch()-cached entry and destroys CF's Cache-Tag index.
 *
 * Cache lookup strategy:
 *   Instead of caches.default.match() (Cache API), we rely on cf-cache-status
 *   returned by fetch(). The Cache API and fetch() with cf.cacheKey can have
 *   key mismatches, causing cache.match() to always MISS even when the CDN
 *   has the entry. fetch() + cf-cache-status is the authoritative source.
 *
 * VCL phase mapping:
 *   vcl_recv            → vclRecv()
 *   vcl_hash            → vclHash()
 *   vcl_hit             → cf-cache-status === 'HIT'
 *   vcl_backend_response → vclBackendResponse()
 *   vcl_deliver          → vclDeliver()
 */

// Marketing/tracking query parameters to strip (mirrors VCL regsuball + FPC.js FILTER_GET)
const FILTER_GET = [
    'fbclid', 'fb_ad', 'fb_adid', 'fb_adset', 'fb_campaign', 'fb_adsetid',
    'fb_campaignid', 'utm_id', 'utm_source', 'utm_term', 'utm_medium',
    'utm_cam', 'utm_campaign', 'utm_content', 'utm_creative', 'utm_adcontent',
    'utm_adgroupid', 'matchtype', 'addisttype', 'adposition', 'gad_source',
    'wbraid', 'gbraid', 'gclid', 'gclsrc', 'dclid', 'msclkid', 'srsltid',
    'epik', '_hsenc', '_hsmi', '__hstc', 'affiliate_code', 'referring_service',
    'hsa_cam', 'hsa_acc', 'hsa_grp', 'hsa_ad', 'hsa_src', 'hsa_net', 'hsa_ver',
    'dm_i', 'dm_t', 'ref', 'trk', 'uuid', 'dicbo', 'adgroupid',
    'g_keywordid', 'g_keyword', 'g_campaignid', 'g_campaign', 'g_network',
    'g_adgroupid', 'g_adtype', 'g_acctid', 'g_adid',
    'cq_plac', 'cq_net', 'cq_pos', 'cq_med', 'cq_plt',
    'b_adgroup', 'b_adgroupid', 'b_adid', 'b_campaign', 'b_campaignid',
    'b_isproduct', 'b_productid', 'b_term', 'b_termid',
    '_ga', '_gl', '_kx', '_bta_tid', '_bta_c',
    'cx', 'ie', 'cof', 'siteurl', 'zanpid', 'origin',
    'mc_cid', 'mc_eid',
    'customer-service', 'terms-of-service',
    'add', 'click', 'gtm_debug',
];

// Bypass path segments (mirrors VCL vcl_recv pass rules)
const STATIC_BYPASS = ['/customer', '/checkout', '/catalogsearch'];

// ─────────────────────────────────────────────────────────────────────────────
// Main entry point (ES Module format)
// ─────────────────────────────────────────────────────────────────────────────

export default {
    async fetch(request, env, ctx) {
        const config = buildConfig(env);
        const startTime = Date.now();

        // --- vcl_recv ---
        const recv = vclRecv(request, config);
        if (recv.action === 'pass') {
            const resp = await fetch(request, { cf: { cacheTtl: 0 } });
            return vclDeliver(resp, 'UNCACHEABLE', config, request, {
                reason: 'pass', ttl: null, cacheKey: null,
                cfStatus: null, startTime,
            });
        }

        // --- vcl_hash ---
        const cacheKey = vclHash(recv.request);

        // --- Fetch via CDN cache ---
        // fetch() with cacheEverything stores/retrieves from CF's zone cache.
        // cf-cache-status on the response tells us HIT vs MISS — no separate
        // cache.match() needed (Cache API and fetch() with cf.cacheKey can
        // disagree on cache key format, causing false MISSes).
        const response = await fetch(recv.request, {
            cf: {
                cacheKey: cacheKey.url,
                cacheEverything: true,
                cacheTtlByStatus: {
                    '200-299': config.defaultTtl,
                    '404': 60,
                    '500-599': 0,
                },
            },
        });

        const cfStatus = response.headers.get('cf-cache-status');

        // --- vcl_hit ---
        if (cfStatus === 'HIT') {
            // Hit-for-pass marker — known uncacheable URL, fetch from origin
            if (response.headers.get('X-FPC-HFP') === '1') {
                const passResp = await fetch(recv.request, { cf: { cacheTtl: 0 } });
                return vclDeliver(passResp, 'UNCACHEABLE', config, request, {
                    reason: 'hit-for-pass', ttl: null, cacheKey: cacheKey.url,
                    cfStatus, startTime,
                });
            }
            return vclDeliver(response, 'HIT', config, request, {
                reason: 'hit', ttl: null, cacheKey: cacheKey.url,
                cfStatus, startTime,
            });
        }

        // --- vcl_backend_response (MISS / EXPIRED / DYNAMIC) ---
        const backend = vclBackendResponse(recv.request, response, config);

        if (backend.cacheable) {
            // CDN cached this via cacheTtlByStatus — Cache-Tag index preserved
            // for purge-by-tag. Do NOT use cache.put() here.
            return vclDeliver(response, 'MISS', config, request, {
                reason: backend.reason, ttl: backend.ttl, cacheKey: cacheKey.url,
                cfStatus, startTime,
            });
        }

        // Uncacheable — cacheTtlByStatus may have force-cached a private response.
        // Overwrite the bad CDN entry with a hit-for-pass marker via Cache API.
        // cache.put() writes to the same zone cache that fetch() reads, so the
        // next fetch() gets the marker (cf-cache-status: HIT + X-FPC-HFP: 1)
        // instead of the stale private response. This intentionally destroys
        // the Cache-Tag index for this entry — uncacheable responses have no
        // tags to preserve.
        if (backend.ttl > 0) {
            ctx.waitUntil(
                caches.default.put(
                    new Request(cacheKey.url),
                    buildHitForPassMarker(config)
                )
            );
        }
        return vclDeliver(response, 'UNCACHEABLE', config, request, {
            reason: backend.reason, ttl: backend.ttl, cacheKey: cacheKey.url,
            cfStatus, startTime,
        });
    },
};

// ─────────────────────────────────────────────────────────────────────────────
// Configuration
// ─────────────────────────────────────────────────────────────────────────────

function buildConfig(env) {
    return {
        debug:       env.DEBUG === 'true',
        defaultTtl:  parseInt(env.DEFAULT_TTL || '86400', 10),
        hfpTtl:      parseInt(env.HFP_TTL || '120', 10),
        adminPath:   env.ADMIN_PATH || 'admin',
        bypassPaths: (env.BYPASS_PATHS || '').split(',').map(s => s.trim()).filter(Boolean),
    };
}

// ─────────────────────────────────────────────────────────────────────────────
// vcl_recv — Request processing (method filter, bypass, URL normalization)
// Mirrors varnish7.vcl lines 25-107
// ─────────────────────────────────────────────────────────────────────────────

function vclRecv(request, config) {
    // Only GET and HEAD proceed to cache lookup (VCL line 61-63)
    if (request.method !== 'GET' && request.method !== 'HEAD') {
        return { action: 'pass', request };
    }

    const url = new URL(request.url);
    const pathname = url.pathname;

    // Bypass paths (VCL lines 66-73, 91-93, 102-104)
    if (shouldBypassPath(pathname, config)) {
        return { action: 'pass', request };
    }

    // Bypass authenticated GraphQL without X-Magento-Cache-Id (VCL line 102-104)
    if (pathname.includes('/graphql')
        && !request.headers.get('X-Magento-Cache-Id')
        && (request.headers.get('Authorization') || '').startsWith('Bearer')) {
        return { action: 'pass', request };
    }

    // Normalize URL: strip marketing params, deduplicate, sort
    const normalizedUrl = normalizeUrl(url);
    const normalizedRequest = new Request(normalizedUrl.toString(), request);

    return { action: 'hash', request: normalizedRequest };
}

function shouldBypassPath(pathname, config) {
    const lower = pathname.toLowerCase();

    // Static bypass segments: /customer, /checkout, /catalogsearch
    for (const seg of STATIC_BYPASS) {
        if (lower.includes(seg)) return true;
    }

    // Admin path
    if (lower.includes('/' + config.adminPath)) return true;

    // Static files — CF CDN handles natively (VCL line 91-93)
    if (/^\/(pub\/)?(media|static)\//.test(lower)) return true;

    // Health check (VCL line 70-73)
    if (/^\/(pub\/)?health_check\.php$/.test(lower)) return true;

    // Extra configurable bypass paths
    for (const seg of config.bypassPaths) {
        if (seg && lower.includes(seg)) return true;
    }

    return false;
}

// ─────────────────────────────────────────────────────────────────────────────
// vcl_hash — Cache key computation
// Mirrors varnish7.vcl lines 109-142
// ─────────────────────────────────────────────────────────────────────────────

function vclHash(request) {
    const url = new URL(request.url);
    const isGraphQL = url.pathname.includes('/graphql');

    // X-Magento-Vary cookie → hash_data (VCL line 110-112)
    if (!isGraphQL || !request.headers.get('X-Magento-Cache-Id')) {
        const vary = extractCookieValue(request.headers.get('Cookie'), 'X-Magento-Vary');
        if (vary) {
            url.searchParams.set('__vary', vary);
        }
    }

    // SSL indicator — CF always terminates SSL (mirrors VCL ssl_offloaded_header hash_data)
    url.searchParams.set('__ssl', '1');

    // GraphQL-specific hash components (VCL lines 125-142)
    if (isGraphQL) {
        const cacheId = request.headers.get('X-Magento-Cache-Id');
        if (cacheId) {
            url.searchParams.set('__gql_cache_id', cacheId);
            if ((request.headers.get('Authorization') || '').startsWith('Bearer')) {
                url.searchParams.set('__gql_auth', '1');
            }
        }
        const store = request.headers.get('Store');
        if (store) url.searchParams.set('__gql_store', store);
        const currency = request.headers.get('Content-Currency');
        if (currency) url.searchParams.set('__gql_currency', currency);
    }

    return new Request(url.toString());
}

// ─────────────────────────────────────────────────────────────────────────────
// vcl_backend_response — Cacheability check
// Mirrors varnish7.vcl lines 144-201
// ─────────────────────────────────────────────────────────────────────────────

function vclBackendResponse(request, response, config) {
    const cc = response.headers.get('Cache-Control') || '';
    const sc = response.headers.get('Surrogate-Control') || '';

    // 1. Only cache 200 and 404 (VCL line 161)
    if (response.status !== 200 && response.status !== 404) {
        return { cacheable: false, ttl: config.hfpTtl, reason: 'status-' + response.status };
    }

    // 2. Cache-Control: private → uncacheable (VCL line 161)
    if (cc.includes('private')) {
        return { cacheable: false, ttl: config.hfpTtl, reason: 'private' };
    }

    // 3. Parse TTL
    const ttl = parseTtl(cc, config.defaultTtl);

    // 4. Transitional X-Magento-Vary: request has no cookie but response sets one (VCL lines 175-180)
    const url = new URL(request.url);
    if (!url.pathname.includes('/graphql') || !request.headers.get('X-Magento-Cache-Id')) {
        const requestHasVary = (request.headers.get('Cookie') || '').includes('X-Magento-Vary=');
        const setCookie = response.headers.get('Set-Cookie') || '';
        const responseSetsVary = setCookie.includes('X-Magento-Vary=');
        if (!requestHasVary && responseSetsVary) {
            return { cacheable: false, ttl: 0, reason: 'transitional-vary' };
        }
    }

    // 5. no-cache, no-store, must-revalidate, max-age=0, Surrogate-control: no-store, Vary: *
    //    (VCL lines 185-193 + Magento's standard non-cacheable header set)
    const hasNoCacheDirective = cc.includes('no-cache') || cc.includes('no-store')
        || cc.includes('must-revalidate') || cc.includes('max-age=0');
    if (ttl <= 0
        || sc.includes('no-store')
        || (!response.headers.get('Surrogate-Control') && hasNoCacheDirective)
        || response.headers.get('Vary') === '*') {
        return { cacheable: false, ttl: config.hfpTtl, reason: 'no-store' };
    }

    // 6. GraphQL cache-id mismatch (VCL lines 196-199)
    if (url.pathname.includes('/graphql')
        && request.headers.get('X-Magento-Cache-Id')
        && request.headers.get('X-Magento-Cache-Id') !== response.headers.get('X-Magento-Cache-Id')) {
        return { cacheable: false, ttl: 0, reason: 'graphql-cache-id-mismatch' };
    }

    return { cacheable: true, ttl, reason: 'ok' };
}

function parseTtl(cc, defaultTtl) {
    const sm = cc.match(/s-maxage=(\d+)/);
    if (sm) return parseInt(sm[1], 10);
    const ma = cc.match(/max-age=(\d+)/);
    if (ma) return parseInt(ma[1], 10);
    return defaultTtl;
}

// ─────────────────────────────────────────────────────────────────────────────
// Cache response builders
// ─────────────────────────────────────────────────────────────────────────────

function buildHitForPassMarker(config) {
    return new Response(null, {
        status: 204,
        headers: {
            'Cache-Control': 's-maxage=' + config.hfpTtl,
            'X-FPC-HFP': '1',
        },
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// vcl_deliver — Response cleanup before delivery to client
// Mirrors varnish7.vcl lines 204-231
// ─────────────────────────────────────────────────────────────────────────────

function vclDeliver(response, cacheStatus, config, originalRequest, debugMeta = {}) {
    const resp = new Response(response.body, response);
    const url = new URL(originalRequest.url);
    const isStatic = /^\/(pub\/)?(media|static)\//.test(url.pathname);

    // Debug mode: worker env DEBUG=true OR origin sent X-Magento-Debug (VCL lines 156, 221-224)
    const isDebug = config.debug || resp.headers.has('X-Magento-Debug');

    // Set the worker's cache status, visible in both debug and production.
    resp.headers.set('cf-cache-status', cacheStatus === 'HIT' ? 'HIT' : cacheStatus === 'MISS' ? 'MISS' : 'BYPASS');

    // Strip Set-Cookie from cached responses (VCL line 181: unset beresp.http.set-cookie).
    // cacheTtlByStatus auto-strips Set-Cookie from the CDN copy, but belt-and-suspenders:
    // ensure no stale cookies leak to another user on HIT.
    if (cacheStatus === 'HIT') {
        resp.headers.delete('Set-Cookie');
    }

    // Preserve origin Cache-Control before browser no-cache override
    const originCacheControl = resp.headers.get('Cache-Control');

    // Prevent browser caching for non-static, non-private pages (VCL lines 215-219)
    if (!isStatic && !(originCacheControl || '').includes('private')) {
        resp.headers.set('Pragma', 'no-cache');
        resp.headers.set('Expires', '-1');
        resp.headers.set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    // Strip internal/upstream headers (VCL lines 221-230)
    if (!isDebug) {
        resp.headers.delete('Age');
    }
    resp.headers.delete('X-Magento-Debug');
    if (!isDebug) {
        resp.headers.delete('X-Magento-Tags');
        resp.headers.delete('Cache-Tag');
        resp.headers.delete('Surrogate-Control');
    }
    resp.headers.delete('X-Powered-By');
    resp.headers.delete('Server');
    resp.headers.delete('X-Varnish');
    resp.headers.delete('Via');
    resp.headers.delete('Link');
    resp.headers.delete('X-FPC-Original-Expires');
    resp.headers.delete('X-FPC-HFP');

    // Debug headers — rich diagnostics for staging/development
    if (isDebug) {
        const workerMs = debugMeta.startTime ? Date.now() - debugMeta.startTime : 0;
        resp.headers.set('X-FPC-Cache-Status', cacheStatus);
        resp.headers.set('X-FPC-CDN-Status', debugMeta.cfStatus || 'none');
        resp.headers.set('X-FPC-Reason', debugMeta.reason || 'unknown');
        if (debugMeta.ttl != null) {
            resp.headers.set('X-FPC-TTL', String(debugMeta.ttl));
        }
        if (debugMeta.cacheKey) {
            resp.headers.set('X-FPC-Cache-Key', debugMeta.cacheKey);
        }
        if (originCacheControl) {
            resp.headers.set('X-FPC-Origin-Cache-Control', originCacheControl);
        }
        resp.headers.set('X-FPC-Origin-Status', String(response.status));
        resp.headers.set('Server-Timing', `fpc;desc="${cacheStatus} ${debugMeta.reason || 'unknown'}";dur=${workerMs}`);
    }

    return resp;
}

// ─────────────────────────────────────────────────────────────────────────────
// URL normalization
// Mirrors VCL regsuball for marketing params + FPC.js normalizeUrl()
// ─────────────────────────────────────────────────────────────────────────────

function normalizeUrl(url) {
    const normalized = new URL(url.toString());

    // Strip tracking/marketing params
    for (const param of FILTER_GET) {
        normalized.searchParams.delete(param);
    }

    // Deduplicate and sort remaining params alphabetically
    const unique = new Map();
    for (const [key, value] of normalized.searchParams.entries()) {
        if (!unique.has(key)) {
            unique.set(key, value);
        }
    }

    const sorted = new URLSearchParams();
    [...unique.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .forEach(([key, value]) => sorted.append(key, value));

    normalized.search = sorted.toString();
    return normalized;
}

// ─────────────────────────────────────────────────────────────────────────────
// Utilities
// ─────────────────────────────────────────────────────────────────────────────

function extractCookieValue(cookieHeader, name) {
    if (!cookieHeader) return null;
    const match = cookieHeader.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
    return match ? match[1] : null;
}
