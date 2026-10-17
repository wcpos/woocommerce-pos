import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { generateKeyPairSync, sign } from 'node:crypto';

const require = createRequire(import.meta.url);
const loader = require('../../assets/js/pos-loader.js');
const { privateKey, publicKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' });
const jwk = publicKey.export({ format: 'jwk' });
const descriptor = {
  app_version: '2.0.0', build_id: '1234567890abcdef', built_at: '2026-10-17T00:00:00Z',
  files: { css: 'style.css', bundles: ['runtime.js', 'entry.js'] }, lane: 'next',
  monorepo_sha: 'abc1234', prerelease: true, run_id: 1,
  sri: { 'style.css': 'sha384-css', 'runtime.js': 'sha384-runtime', 'entry.js': 'sha384-entry' },
  worker_rxdb_version: '17.6.0',
};
function signed(d = descriptor) {
  const text = JSON.stringify(d);
  return { descriptor: text, signature: sign('sha256', Buffer.from(text), {
    key: privateKey, dsaEncoding: 'ieee-p1363',
  }).toString('base64'), build_id: d.build_id, bundle_base: 'https://cdn.test/build',
  fallback_base: 'https://mirror.test/build', killed: false, message: null };
}
function response(body, status = 200) {
  return { status, ok: status >= 200 && status < 300,
    json: async () => typeof body === 'string' ? JSON.parse(body) : body,
    text: async () => typeof body === 'string' ? body : JSON.stringify(body) };
}
function harness() {
  const primary = signed();
  const routes = new Map([
    ['manifest', response(primary)],
    ['https://mirror.test/build/bundle.json', response(primary.descriptor)],
    ['https://mirror.test/build/bundle.sig', response(primary.signature)],
    ['metadata?v=hash', response({ fileMetadata: { web: { css: 'old.css', bundles: ['old.js'] } } })],
  ]);
  const calls = [], assets = [], bases = [], beacons = [], values = new Map();
  const config = { manifest_url: 'manifest', fallback_manifest: 'metadata?v=hash',
    fallback_base: 'https://mirror.test/build', beacon_url: 'beacon', timeout_ms: 10,
    lane: 'next', worker_version: '17.6.0', plugin_version: '2.0.0', pro_version: null,
    signing_keys: [jwk] };
  const deps = {
    fetch: async (url, options) => {
      calls.push([url, options]);
      if (!routes.has(url)) throw new Error('Unexpected fetch: ' + url);
      const value = routes.get(url);
      return typeof value === 'function' ? value(options) : value;
    },
    subtle: globalThis.crypto.subtle,
    loadCSS: (url, ok, fail, integrity) => { assets.push(['css', url, integrity]); ok(); },
    getScript: (url, ok, fail, integrity) => { assets.push(['js', url, integrity]); ok(); },
    setGlobalBase: url => bases.push(url),
    sendBeacon: (url, body) => { beacons.push(JSON.parse(body)); return true; },
    now: () => new Date('2026-10-17T00:00:00Z'),
    storage: { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value),
      removeItem: key => values.delete(key) },
  };
  return { config, deps, routes, calls, assets, bases, beacons, values,
    run: () => loader.boot(config, deps) };
}

test('verified manifest loads CSS then ordered scripts with SRI and reports ok', async () => {
  const h = harness();
  h.config.pro_version = '2.0.0';
  await h.run();
  assert.deepEqual(h.assets, [
    ['css', 'https://cdn.test/build/style.css', 'sha384-css'],
    ['js', 'https://cdn.test/build/runtime.js', 'sha384-runtime'],
    ['js', 'https://cdn.test/build/entry.js', 'sha384-entry'],
  ]);
  assert.deepEqual(h.bases, ['https://cdn.test/build']);
  assert.deepEqual(h.beacons, [{ outcome: 'ok', build_id: '1234567890abcdef',
    plugin: '2.0.0', pro: '2.0.0', at: '2026-10-17T00:00:00.000Z' }]);
  assert.equal(h.calls[0][1].cache, 'no-store');
});

for (const [name, body, reason] of [
  ['bad signature', { ...signed(), signature: 'AAAA' }, 'bad_signature'],
  ['wrong lane', signed({ ...descriptor, lane: '1.11' }), 'wrong_lane'],
  ['wrong worker', signed({ ...descriptor, worker_rxdb_version: '17.4.0' }), 'worker_mismatch'],
  ['no compatible bundle', { error: 'no_compatible_bundle' }, 'no_compatible'],
  ['invalid JSON', 'not JSON', 'manifest_error'],
  ['null manifest', null, 'manifest_error'],
]) {
  test(`${name} loads the verified fallback`, async () => {
    const h = harness();
    h.routes.set('manifest', response(body));
    await h.run();
    assert.equal(h.beacons[0].reason, reason);
    assert.equal(h.beacons[0].outcome, 'fallback');
    assert.deepEqual(h.bases, ['https://mirror.test/build']);
    assert.equal(h.assets[0][2], 'sha384-css');
    assert.equal(h.beacons.at(-1).outcome, 'ok');
    assert.equal(h.calls[1][1].cache, 'no-cache');
  });
}

test('timeout aborts manifest and loads fallback', async () => {
  const h = harness();
  let aborted = false;
  h.routes.set('manifest', ({ signal }) => new Promise((resolve, reject) => {
    signal.addEventListener('abort', () => { aborted = true; reject(new Error('aborted')); });
  }));
  await h.run();
  assert.equal(aborted, true);
  assert.equal(h.beacons[0].reason, 'manifest_timeout');
  assert.equal(h.assets.length, 3);
});

test('kill message is text, loads nothing and beacons killed', async t => {
  const h = harness(), children = [];
  const previous = globalThis.document;
  globalThis.document = { createElement: tag => ({ tag }), body: { appendChild: node => children.push(node) } };
  try {
    h.routes.set('manifest', response({ killed: true, message: '<b>Paused</b>' }));
    await h.run();
    assert.deepEqual(children, [{ tag: 'div', id: 'wcpos-killed', textContent: '<b>Paused</b>' }]);
    assert.equal(h.assets.length, 0);
    assert.equal(h.beacons[0].outcome, 'killed');
  } finally { globalThis.document = previous; }
});

for (const missing of ['bundle.json', 'bundle.sig']) {
  test(`${missing} 404 uses legacy metadata without SRI`, async () => {
    const h = harness();
    h.routes.set('manifest', response({}, 503));
    h.routes.set('https://mirror.test/build/' + missing, response('', 404));
    await h.run();
    assert.deepEqual(h.assets, [ ['css', 'https://mirror.test/build/old.css', undefined],
      ['js', 'https://mirror.test/build/old.js', undefined] ]);
    assert.equal(h.beacons[0].reason, 'manifest_503');
    assert.equal(h.calls.at(-1)[1].cache, 'no-cache');
  });
}

for (const missingCrypto of [false, true]) {
  test(`bypass with ${missingCrypto ? 'no WebCrypto' : 'no manifest'} loads without beacons`, async () => {
    const h = harness();
    if (missingCrypto) h.deps.subtle = null;
    else h.config.manifest_url = null;
    h.routes.set('https://mirror.test/build/bundle.json', response('', 404));
    await h.run();
    assert.equal(h.calls.some(([url]) => url === 'manifest'), false);
    assert.equal(h.assets.length, 2);
    assert.deepEqual(h.beacons, []);
  });
}

test('key rotation tries another key after import failure', async () => {
  const h = harness();
  h.config.signing_keys.unshift({ kty: 'invalid' });
  await h.run();
  assert.equal(h.beacons[0].outcome, 'ok');
});

for (const reason of ['bad_signature', 'wrong_lane', 'worker_mismatch']) {
  test(`fallback ${reason} is terminal`, async t => {
    t.mock.method(console, 'error', () => {});
    const h = harness();
    h.routes.set('manifest', response({}, 500));
    const bad = signed({ ...descriptor, ...(reason === 'wrong_lane' ? { lane: '1.11' } :
      reason === 'worker_mismatch' ? { worker_rxdb_version: '17.4.0' } : {}) });
    h.routes.set('https://mirror.test/build/bundle.json', response(bad.descriptor));
    h.routes.set('https://mirror.test/build/bundle.sig', response(reason === 'bad_signature' ? 'AAAA' : bad.signature));
    await h.run();
    assert.equal(h.assets.length, 0);
    assert.equal(h.beacons.at(-1).outcome, 'fail');
    assert.equal(h.beacons.at(-1).reason, reason);
    assert.equal(h.calls.some(([url]) => url === 'metadata?v=hash'), false);
  });
}

for (const failure of ['css', 'first', 'partial']) {
  test(`asset error ${failure} falls back only before any script executes`, async t => {
    t.mock.method(console, 'error', () => {});
    const h = harness();
    const method = failure === 'css' ? 'loadCSS' : 'getScript';
    const original = h.deps[method];
    h.deps[method] = (url, ok, fail, sri) => {
      if (url.startsWith('https://cdn.test') && (failure !== 'partial' || url.endsWith('entry.js'))) fail(new Error('asset'));
      else original(url, ok, fail, sri);
    };
    await h.run();
    assert.equal(h.beacons[0].reason, failure === 'css' ? 'asset_css' : failure === 'first' ? 'asset_js' : 'asset_js_partial');
    assert.equal(h.beacons[0].outcome, failure === 'partial' ? 'fail' : 'fallback');
    assert.equal(h.bases.length, failure === 'partial' ? 1 : 2);
  });
}

test('failed beacon queues original timestamp and next boot flushes before manifest', async () => {
  const h = harness();
  h.deps.sendBeacon = () => false;
  await h.run();
  const queued = JSON.parse(h.values.get('wcpos_cdn_beacons'));
  assert.equal(queued[0].at, '2026-10-17T00:00:00.000Z');
  h.calls.length = 0;
  h.routes.set('beacon', response('', 204));
  h.deps.sendBeacon = () => true;
  await h.run();
  assert.equal(h.calls[0][0], 'beacon');
  assert.equal(h.calls[0][1].method, 'POST');
  assert.equal(h.calls[0][1].keepalive, true);
  assert.deepEqual(JSON.parse(h.calls[0][1].body), queued[0]);
  assert.equal(h.values.has('wcpos_cdn_beacons'), false);
});

for (const web of [{ bundle: 'single.js' }, { bundles: ['', 'runtime.js', 'entry.js'] }]) {
  test(`legacy metadata supports ${web.bundle ? 'single bundle' : 'ordered bundles'} without CSS`, async () => {
    const h = harness();
    h.config.manifest_url = null;
    h.routes.set('https://mirror.test/build/bundle.json', response('', 404));
    h.routes.set('metadata?v=hash', response({ fileMetadata: { web } }));
    await h.run();
    assert.deepEqual(h.assets.map(a => a[1]), web.bundle ? ['https://mirror.test/build/single.js'] :
      ['https://mirror.test/build/runtime.js', 'https://mirror.test/build/entry.js']);
  });
}

test('network failure falls back and stalled response body is timed out', async () => {
  for (const [route, reason] of [[() => Promise.reject(new Error('offline')), 'manifest_error'],
    [() => ({ status: 200, json: () => new Promise(() => {}) }), 'manifest_timeout']]) {
    const h = harness();
    h.routes.set('manifest', route);
    await h.run();
    assert.equal(h.beacons[0].reason, reason);
    assert.equal(h.beacons[0].outcome, 'fallback');
    assert.equal(h.assets.length, 3);
  }
});

test('browser auto-boot injects DOM elements with SRI and anonymous CORS', async () => {
  const { runInNewContext } = await import('node:vm');
  const { readFileSync } = await import('node:fs');
  const h = harness(), elements = [];
  let finish;
  const finished = new Promise(resolve => { finish = resolve; });
  const window = {
    fetch: h.deps.fetch, crypto: globalThis.crypto, localStorage: h.deps.storage,
    navigator: { sendBeacon: (url, body) => { finish(JSON.parse(body)); return true; } },
  };
  const document = { createElement: tag => ({ tag }), head: {
    appendChild: node => { elements.push(node); node.onload(); },
  } };
  runInNewContext(readFileSync(new URL('../../assets/js/pos-loader.js', import.meta.url), 'utf8'), {
    window, document, initialProps: { loader: h.config }, atob, Uint8Array,
    AbortController, setTimeout, clearTimeout, console,
  });
  assert.equal((await finished).outcome, 'ok');
  assert.deepEqual(elements.map(e => [e.tag, e.integrity, e.crossOrigin]), [
    ['link', 'sha384-css', 'anonymous'], ['script', 'sha384-runtime', 'anonymous'],
    ['script', 'sha384-entry', 'anonymous'],
  ]);
  assert.equal(window.cdnBaseUrl, 'https://cdn.test/build');
  assert.equal(typeof window.WCPOSLoader.boot, 'function');
});

test('beacon queue is capped, handles throws, and survives unsuccessful replay', async () => {
  const h = harness();
  const queued = Array.from({ length: 20 }, () => ({ outcome: 'ok', plugin: '2.0.0', at: '2026-10-16T00:00:00Z' }));
  h.values.set('wcpos_cdn_beacons', JSON.stringify(queued));
  h.routes.set('beacon', response('', 503));
  h.deps.sendBeacon = () => { throw new Error('offline'); };
  await h.run();
  const retained = JSON.parse(h.values.get('wcpos_cdn_beacons'));
  assert.equal(retained.length, 20);
  assert.equal(retained[0].at, '2026-10-16T00:00:00Z');
  assert.equal(retained.at(-1).at, '2026-10-17T00:00:00.000Z');
});
