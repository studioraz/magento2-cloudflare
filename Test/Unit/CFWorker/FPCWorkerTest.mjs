import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../../CFWorker/FPC-worker.js', import.meta.url), 'utf8')
    + '\n//# sourceURL=CFWorker/FPC-worker.js\n';
// Load the Worker ES module without adding package.json to the Magento module.
const { default: worker } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);

const noCacheOptions = { cache: 'no-store' };
const sessionCookies = [
    'PHPSESSID=customer-session; Path=/; HttpOnly; Secure; SameSite=Lax',
    'private_content_version=12345; Path=/; Secure; SameSite=Lax',
];
const context = {
    waitUntil() {
        assert.fail('Unexpected background cache write');
    },
};

globalThis.caches = {
    default: {
        async match() {
            assert.fail('Unexpected cache lookup');
        },
        async put() {
            assert.fail('Unexpected cache write');
        },
    },
};

function sessionResponse(status = 200) {
    const headers = new Headers({
        'Cache-Control': 'private, no-store, no-cache, must-revalidate, max-age=0',
        'cf-cache-status': 'BYPASS',
    });
    for (const cookie of sessionCookies) {
        headers.append('Set-Cookie', cookie);
    }
    if (status === 302) {
        headers.set('Location', '/customer/account/index/');
    }
    return new Response('origin response', { status, headers });
}

const dynamicRequests = [
    { path: '/loginascustomer/login/index/?secret=test-secret&utm_source=test', env: { BYPASS_PATHS: '/loginascustomer' } },
    { path: '/en/loginascustomer/login/index/?secret=test-secret', env: { BYPASS_PATHS: '/loginascustomer' } },
    { path: '/LoginAsCustomer/login/index/?secret=test-secret', env: { BYPASS_PATHS: '/loginascustomer' } },
    { path: '/customer/section/load/?sections=loggedAsCustomer' },
    { path: '/customer/account/index/', method: 'HEAD' },
    { path: '/checkout/cart/' },
    { path: '/catalogsearch/result/?q=food' },
    { path: '/health_check.php' },
    { path: '/pub/health_check.php' },
    { path: '/rest/V1/example', env: { BYPASS_PATHS: '/rest' } },
    { path: '/graphql?query=example', headers: { Authorization: 'Bearer test-token' } },
];

for (const { path, method = 'GET', env = {}, headers = {} } of dynamicRequests) {
    test(`${method} ${path} bypasses cache and preserves session cookies`, async (t) => {
        const request = new Request(`https://example.com${path}`, {
            method,
            redirect: 'manual',
            headers: { Cookie: 'PHPSESSID=guest-session', ...headers },
        });
        const fetchMock = t.mock.method(globalThis, 'fetch', async (incoming, options) => {
            assert.ok(incoming === request, 'Pass must forward the original Request');
            assert.deepEqual(options, noCacheOptions);
            return sessionResponse();
        });

        const response = await worker.fetch(request, { DEBUG: 'true', ...env }, context);

        assert.equal(fetchMock.mock.callCount(), 1);
        assert.equal(response.status, 200);
        assert.deepEqual(response.headers.getSetCookie(), sessionCookies);
        assert.equal(response.headers.get('X-FPC-Reason'), 'pass');
        assert.equal(response.headers.get('CF-Cache-Status'), 'BYPASS');
    });
}

for (const status of [302, 404, 503]) {
    test(`dynamic pass disables caching and preserves cookies for status ${status}`, async (t) => {
        const fetchMock = t.mock.method(globalThis, 'fetch', async (_, options) => {
            assert.deepEqual(options, noCacheOptions);
            return sessionResponse(status);
        });

        const response = await worker.fetch(
            new Request('https://example.com/customer/section/load/'),
            {},
            context
        );

        assert.equal(fetchMock.mock.callCount(), 1);
        assert.equal(response.status, status);
        assert.deepEqual(response.headers.getSetCookie(), sessionCookies);
    });
}

test('regular customer POST login preserves the request body, redirect and cookies', async (t) => {
    const request = new Request('https://example.com/customer/account/loginPost/', {
        method: 'POST',
        redirect: 'manual',
        body: 'login[username]=test@example.com&form_key=test-form-key',
    });
    const fetchMock = t.mock.method(globalThis, 'fetch', async (incoming, options) => {
        assert.equal(incoming, request);
        assert.deepEqual(options, noCacheOptions);
        return sessionResponse(302);
    });

    const response = await worker.fetch(request, {}, context);

    assert.equal(fetchMock.mock.callCount(), 1);
    assert.equal(await request.text(), 'login[username]=test@example.com&form_key=test-form-key');
    assert.equal(response.status, 302);
    assert.equal(response.headers.get('Location'), '/customer/account/index/');
    assert.deepEqual(response.headers.getSetCookie(), sessionCookies);
});

for (const path of ['/static/frontend/styles.css', '/media/catalog/image.jpg', '/pub/static/app.js',
    '/pub/media/image.webp']) {
    test(`asset ${path} bypasses Worker caching and keeps origin Cache-Control`, async (t) => {
        const request = new Request(`https://example.com${path}`);
        const fetchMock = t.mock.method(globalThis, 'fetch', async (incoming, options) => {
            assert.equal(incoming, request);
            assert.deepEqual(options, noCacheOptions);
            return new Response('asset', {
                headers: { 'Cache-Control': 'public, max-age=3600', 'cf-cache-status': 'HIT' },
            });
        });

        const response = await worker.fetch(request, { DEBUG: 'true' }, context);

        assert.equal(fetchMock.mock.callCount(), 1);
        assert.equal(response.headers.get('Cache-Control'), 'public, max-age=3600');
        assert.equal(response.headers.get('CF-Cache-Status'), 'BYPASS');
        assert.equal(await response.text(), 'asset');
    });
}

test('HEAD static assets keep origin Cache-Control', async (t) => {
    const request = new Request('https://example.com/static/frontend/styles.css', { method: 'HEAD' });
    t.mock.method(globalThis, 'fetch', async (incoming, options) => {
        assert.equal(incoming, request);
        assert.deepEqual(options, noCacheOptions);
        return new Response(null, { headers: { 'Cache-Control': 'public, max-age=3600' } });
    });

    const response = await worker.fetch(request, {}, context);

    assert.equal(response.headers.get('Cache-Control'), 'public, max-age=3600');
});

test('POST to a static path still uses dynamic pass', async (t) => {
    const request = new Request('https://example.com/static/example', { method: 'POST' });
    t.mock.method(globalThis, 'fetch', async (incoming, options) => {
        assert.equal(incoming, request);
        assert.deepEqual(options, noCacheOptions);
        return sessionResponse();
    });

    const response = await worker.fetch(request, {}, context);

    assert.deepEqual(response.headers.getSetCookie(), sessionCookies);
});

test('hit-for-pass fetch bypasses cache and preserves origin session cookies', async (t) => {
    const request = new Request('https://example.com/custom/session/?utm_source=test', {
        headers: { Cookie: 'PHPSESSID=guest-session; X-Magento-Vary=guest-variation' },
        redirect: 'manual',
    });
    t.mock.method(caches.default, 'match', async (key) => {
        assert.equal(new URL(key.url).pathname, '/custom/session/');
        return new Response(null, { status: 204, headers: { 'X-FPC-HFP': '1' } });
    });
    const fetchMock = t.mock.method(globalThis, 'fetch', async (incoming, options) => {
        assert.deepEqual(options, noCacheOptions);
        assert.equal(incoming.url, 'https://example.com/custom/session/');
        assert.equal(incoming.headers.get('Cookie'), request.headers.get('Cookie'));
        assert.equal(incoming.redirect, 'manual');
        return sessionResponse();
    });

    const response = await worker.fetch(request, { DEBUG: 'true' }, context);

    assert.equal(fetchMock.mock.callCount(), 1);
    assert.equal(response.status, 200);
    assert.deepEqual(response.headers.getSetCookie(), sessionCookies);
    assert.equal(response.headers.get('X-FPC-Reason'), 'hit-for-pass');
    assert.equal(response.headers.get('X-FPC-HFP'), null);
});

for (const path of ['/', '/category/', '/product.html']) {
    test(`${path} caches by activation and vary cookie after Magento enables Cloudflare FPC`, async (t) => {
        const stored = new Map();
        const fetchMock = t.mock.method(globalThis, 'fetch', async (incoming, options) => {
            assert.equal(incoming.url, `https://example.com${path}`);
            assert.deepEqual(options, noCacheOptions);
            return new Response('storefront page', {
                headers: {
                    'Cache-Control': 'public, s-maxage=3600',
                    'X-Magento-Tags': 'cat_p_1',
                    'Set-Cookie': sessionCookies[0],
                    ...(enabled ? { 'X-SR-Cloudflare-FPC': '1' } : {}),
                },
            });
        });
        const request = new Request(`https://example.com${path}?utm_source=test`, {
            headers: { Cookie: 'X-Magento-Vary=guest-variation' },
        });
        const env = { MAGENTO_CACHE_NAMESPACE: 'first-activation' };
        const cacheKey = new URL(`https://example.com${path}`);
        cacheKey.searchParams.set('__fpc', '2-first-activation');
        cacheKey.searchParams.set('__vary', 'guest-variation');
        cacheKey.searchParams.set('__ssl', '1');
        t.mock.method(caches.default, 'match', async (key) => stored.get(key.url)?.clone());
        t.mock.method(caches.default, 'put', async (key, response) => {
            assert.equal(key.url, cacheKey.toString());
            stored.set(key.url, response);
        });
        let enabled = false;

        const before = await worker.fetch(request, env, context);
        assert.equal(before.headers.get('CF-Cache-Status'), 'BYPASS');
        assert.equal(stored.size, 0);

        enabled = true;
        const writes = [];
        const miss = await worker.fetch(request, env, { waitUntil: (write) => writes.push(write) });
        await Promise.all(writes);
        assert.equal(miss.headers.get('CF-Cache-Status'), 'MISS');
        assert.equal(miss.headers.get('X-SR-Cloudflare-FPC'), null);
        assert.deepEqual(miss.headers.getSetCookie(), [sessionCookies[0]]);
        assert.equal(stored.get(cacheKey.toString()).headers.get('Cache-Tag'), 'example_com,cat_p_1');
        assert.deepEqual(stored.get(cacheKey.toString()).headers.getSetCookie(), []);

        const hit = await worker.fetch(request, env, context);
        assert.equal(hit.headers.get('CF-Cache-Status'), 'HIT');
        assert.deepEqual(hit.headers.getSetCookie(), []);
        assert.equal(await hit.text(), 'storefront page');
        assert.equal(fetchMock.mock.callCount(), 2);

        enabled = false;
        const next = await worker.fetch(request, { MAGENTO_CACHE_NAMESPACE: 'next-activation' }, context);
        assert.equal(next.headers.get('CF-Cache-Status'), 'BYPASS');
        assert.equal(fetchMock.mock.callCount(), 3);
    });
}
