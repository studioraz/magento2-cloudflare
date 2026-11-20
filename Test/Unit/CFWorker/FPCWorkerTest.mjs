import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../../CFWorker/FPC-worker.js', import.meta.url), 'utf8')
    + '\n//# sourceURL=CFWorker/FPC-worker.js\n';
// Load the Worker ES module without adding package.json to the Magento module.
const { default: worker } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);

const noCacheOptions = { cf: { cacheTtlByStatus: { '100-599': -1 } } };
const sessionCookies = [
    'PHPSESSID=customer-session; Path=/; HttpOnly; Secure; SameSite=Lax',
    'private_content_version=12345; Path=/; Secure; SameSite=Lax',
];
const context = {
    waitUntil() {
        assert.fail('Unexpected background cache write');
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
    { path: '/loginascustomer/login/index/?secret=test-secret&utm_source=test' },
    { path: '/en/loginascustomer/login/index/?secret=test-secret' },
    { path: '/LoginAsCustomer/login/index/?secret=test-secret' },
    { path: '/customer/section/load/?sections=loggedAsCustomer' },
    { path: '/customer/account/index/', method: 'HEAD' },
    { path: '/checkout/cart/' },
    { path: '/catalogsearch/result/?q=food' },
    { path: '/backoffice/', env: { ADMIN_PATH: 'backoffice' } },
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
        assert.equal(response.headers.get('X-FPC-CDN-Status'), 'BYPASS');
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
    '/pub/media/image.webp', '/STATIC/frontend/app.js']) {
    test(`native asset ${path} keeps CDN fetching and origin Cache-Control`, async (t) => {
        const request = new Request(`https://example.com${path}`);
        const fetchMock = t.mock.method(globalThis, 'fetch', async (incoming, options) => {
            assert.equal(incoming, request);
            assert.equal(options, undefined);
            return new Response('asset', {
                headers: { 'Cache-Control': 'public, max-age=3600', 'cf-cache-status': 'HIT' },
            });
        });

        const response = await worker.fetch(request, { DEBUG: 'true' }, context);

        assert.equal(fetchMock.mock.callCount(), 1);
        assert.equal(response.headers.get('Cache-Control'), 'public, max-age=3600');
        assert.equal(response.headers.get('X-FPC-CDN-Status'), 'HIT');
        assert.equal(await response.text(), 'asset');
    });
}

test('HEAD static assets retain native CDN fetching', async (t) => {
    const request = new Request('https://example.com/static/frontend/styles.css', { method: 'HEAD' });
    t.mock.method(globalThis, 'fetch', async (incoming, options) => {
        assert.equal(incoming, request);
        assert.equal(options, undefined);
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
    let callCount = 0;
    const fetchMock = t.mock.method(globalThis, 'fetch', async (incoming, options) => {
        callCount++;
        if (callCount === 1) {
            assert.equal(options.cf.cacheEverything, true);
            return new Response(null, {
                status: 204,
                headers: { 'cf-cache-status': 'HIT', 'X-FPC-HFP': '1' },
            });
        }
        assert.deepEqual(options, noCacheOptions);
        assert.equal(incoming.url, 'https://example.com/custom/session/');
        assert.equal(incoming.headers.get('Cookie'), request.headers.get('Cookie'));
        assert.equal(incoming.redirect, 'manual');
        return sessionResponse();
    });

    const response = await worker.fetch(request, { DEBUG: 'true' }, context);

    assert.equal(fetchMock.mock.callCount(), 2);
    assert.equal(response.status, 200);
    assert.deepEqual(response.headers.getSetCookie(), sessionCookies);
    assert.equal(response.headers.get('X-FPC-Reason'), 'hit-for-pass');
    assert.equal(response.headers.get('X-FPC-HFP'), null);
});

for (const path of ['/', '/category/', '/product.html']) {
    for (const status of ['HIT', 'MISS']) {
        test(`${status} ${path} retains the FPC fetch configuration`, async (t) => {
            const fetchMock = t.mock.method(globalThis, 'fetch', async (_, options) => {
                const key = new URL(`https://example.com${path}`);
                key.searchParams.set('__vary', 'guest-variation');
                key.searchParams.set('__ssl', '1');
                assert.deepEqual(options, {
                    cf: {
                        cacheKey: key.toString(),
                        cacheEverything: true,
                        cacheTtlByStatus: { '200-299': 3600, '404': 60, '500-599': 0 },
                    },
                });
                const headers = new Headers({
                    'Cache-Control': 'public, s-maxage=3600',
                    'cf-cache-status': status,
                });
                if (status === 'HIT') {
                    headers.append('Set-Cookie', sessionCookies[0]);
                }
                return new Response('cached page', { headers });
            });
            const request = new Request(`https://example.com${path}?utm_source=test`, {
                headers: { Cookie: 'X-Magento-Vary=guest-variation' },
            });

            const response = await worker.fetch(request, { DEFAULT_TTL: '3600' }, context);

            assert.equal(fetchMock.mock.callCount(), 1);
            assert.equal(response.headers.get('cf-cache-status'), status);
            assert.deepEqual(response.headers.getSetCookie(), []);
            assert.equal(await response.text(), 'cached page');
        });
    }
}
