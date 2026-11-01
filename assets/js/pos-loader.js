(function () {
    'use strict';
    // A cold edge fills within a second or two; one retry covers it without doubling a real outage's wait.
    var RETRY_DELAY_MS = 1500;
    function getScript(source, callback, onError, integrity) {
        var script = document.createElement('script');
        script.async = true;
        script.onload = script.onreadystatechange = function(_, isAbort) {
            if (isAbort || !script.readyState || /loaded|complete/.test(script.readyState)) {
                script.onload = script.onreadystatechange = null;
                script = undefined;
                if (!isAbort && callback) setTimeout(callback, 0);
            }
        };
        script.onerror = function() {
            script.onload = script.onreadystatechange = null;
            if (onError) onError(new Error('Failed to load script: ' + source));
        };
        if (integrity) {
            script.integrity = integrity;
            script.crossOrigin = 'anonymous';
        }
        script.src = source;
        document.head.appendChild(script);
    }

    function loadCSS(source, callback, onError, integrity) {
        var link = document.createElement('link');
        if (integrity) {
            link.integrity = integrity;
            link.crossOrigin = 'anonymous';
        }
        link.rel = 'stylesheet';
        link.href = source;
        link.onload = function() {
            if (callback) callback();
        };
        link.onerror = function() {
            console.error('Failed to load CSS file:', source);
            if (onError) onError(new Error('Failed to load CSS: ' + source));
        };
        document.head.appendChild(link);
    }

    function base64ToBytes(value) {
        var raw = atob(value), bytes = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
        return bytes;
    }
    function utf8Bytes(value) {
        var raw = unescape(encodeURIComponent(value)), bytes = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
        return bytes;
    }
    function checkDescriptor(d, config) {
        if (config.lane != null && d.lane !== config.lane) return 'wrong_lane';
        if (config.worker_version != null && d.worker_rxdb_version !== config.worker_version) return 'worker_mismatch';
        return null;
    }
    function verifyDescriptor(text, signature, keys, subtle) {
        function attempt(index) {
            if (index >= keys.length) return Promise.resolve(false);
            return Promise.resolve().then(function () {
                return subtle.importKey('jwk', keys[index], { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify']);
            }).then(function (key) {
                return subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, key, base64ToBytes(signature), utf8Bytes(text));
            }).catch(function () { return false; }).then(function (valid) {
                return valid || attempt(index + 1);
            });
        }
        return attempt(0);
    }
    function boot(config, deps) {
        var queueKey = 'wcpos_cdn_beacons';
        var quiet = !config.manifest_url || !deps.subtle;
        function queued() {
            try { return JSON.parse(deps.storage.getItem(queueKey) || '[]'); } catch (_) { return []; }
        }
        function beacon(outcome, reason, buildId) {
            if (quiet) return;
            var body = { outcome: outcome, plugin: config.plugin_version, at: new Date(deps.now()).toISOString() };
            if (config.pro_version) body.pro = config.pro_version;
            if (reason) body.reason = reason;
            if (buildId) body.build_id = buildId;
            try { if (deps.sendBeacon(config.beacon_url, JSON.stringify(body))) return; } catch (_) {}
            try { deps.storage.setItem(queueKey, JSON.stringify(queued().concat([body]).slice(-20))); } catch (_) {}
        }
        function flushQueuedBeacons() {
            var pending = queued();
            if (!pending.length) return;
            // Telemetry must never delay the POS boot, even when the CDN is down.
            Promise.all(pending.map(function (body) {
                return deps.fetch(config.beacon_url, { method: 'POST', keepalive: true, body: JSON.stringify(body) })
                    .then(function (response) { if (!response.ok) throw new Error('beacon'); });
            })).then(function () { deps.storage.removeItem(queueKey); }).catch(function () {});
        }
        function fail(reason) {
            beacon('fail', reason);
            console.error('Error loading POS bundle:', reason);
        }
        function checked(text, signature) {
            return verifyDescriptor(text, signature, config.signing_keys, deps.subtle).then(function (valid) {
                if (!valid) throw 'bad_signature';
                var d;
                try { d = JSON.parse(text); } catch (_) { throw 'manifest_error'; }
                var reason = checkDescriptor(d, config);
                if (reason) throw reason;
                return d;
            });
        }
        function loadAssets(d, base) {
            function loadWithRetry(load, path, resolve, reject) {
                load(base + '/' + path, resolve, function () {
                    deps.setTimeout(function () {
                        load(base + '/' + path, resolve, reject, d.sri[path]);
                    }, RETRY_DELAY_MS);
                }, d.sri[path]);
            }
            function scripts(index) {
                if (index >= d.files.bundles.length) return Promise.resolve();
                return new Promise(function (resolve, reject) {
                    var path = d.files.bundles[index];
                    loadWithRetry(deps.getScript, path, resolve, function () {
                        reject(index === 0 ? 'asset_js' : 'asset_js_partial');
                    });
                }).then(function () { return scripts(index + 1); });
            }
            return new Promise(function (resolve, reject) {
                if (!d.files.css) return resolve();
                loadWithRetry(deps.loadCSS, d.files.css, resolve, function () { reject('asset_css'); });
            }).then(function () { return scripts(0); }).then(function () { beacon('ok', null, d.build_id); });
        }
        function legacy() {
            return deps.fetch(config.fallback_manifest, { cache: 'no-cache' }).then(function (response) {
                return response.json();
            }).then(function (data) {
                var webMeta = (data && data.fileMetadata && data.fileMetadata.web) || {};
                var bundles = Array.isArray(webMeta.bundles) ? webMeta.bundles.filter(Boolean) : (webMeta.bundle ? [webMeta.bundle] : []);
                if (!bundles.length) throw new Error('No JavaScript bundles declared in metadata.json');
                return loadAssets({ files: { css: webMeta.css, bundles: bundles }, sri: {} }, config.fallback_base);
            });
        }
        function loadFromFallback(reason) {
            deps.setGlobalBase(config.fallback_base);
            if (reason !== 'no_manifest') beacon('fallback', reason);
            // Insecure contexts retain today's unsigned metadata boot, not an unverifiable signed boot.
            if (!deps.subtle) return legacy().catch(function () { fail('manifest_error'); });
            return Promise.all(['bundle.json', 'bundle.sig'].map(function (path) {
                return deps.fetch(config.fallback_base + '/' + path, { cache: 'no-cache' });
            })).then(function (responses) {
                if (responses.some(function (r) { return r.status === 404; })) return legacy();
                if (responses.some(function (r) { return r.status !== 200; })) throw 'manifest_error';
                return Promise.all(responses.map(function (r) { return r.text(); })).then(function (texts) {
                    return checked(texts[0], texts[1]);
                }).then(function (d) { return loadAssets(d, config.fallback_base); });
            }).catch(function (error) { fail(typeof error === 'string' ? error : 'manifest_error'); });
        }
        flushQueuedBeacons();
        if (quiet) return loadFromFallback('no_manifest');
        var controller = new AbortController(), timer;
        var request = new Promise(function (resolve, reject) {
            timer = setTimeout(function () { reject('manifest_timeout'); controller.abort(); }, config.timeout_ms);
            deps.fetch(config.manifest_url, { cache: 'no-store', signal: controller.signal }).then(function (response) {
                if (response.status !== 200) throw 'manifest_' + response.status;
                return response.json();
            }).then(resolve, function (error) {
                // The browser aborts an in-flight fetch when the page navigates or reloads; that
                // is not a CDN failure and must not fall back or beacon. Our own timeout rejects first.
                if (error && error.name === 'AbortError') return reject('navigation_abort');
                reject(typeof error === 'string' ? error : 'manifest_error');
            });
        });
        return request.then(function (manifest) {
            clearTimeout(timer);
            if (!manifest) return loadFromFallback('manifest_error');
            if (manifest.killed) {
                var message = document.createElement('div');
                message.id = 'wcpos-killed';
                message.textContent = manifest.message;
                document.body.appendChild(message);
                beacon('killed');
                return;
            }
            if (manifest.error) return loadFromFallback('no_compatible');
            return checked(manifest.descriptor, manifest.signature).then(function (d) {
                deps.setGlobalBase(manifest.bundle_base);
                return loadAssets(d, manifest.bundle_base);
            }).catch(function (reason) {
                if (reason === 'asset_js_partial') return fail(reason);
                return loadFromFallback(typeof reason === 'string' ? reason : 'manifest_error');
            });
        }, function (reason) {
            clearTimeout(timer);
            if (reason === 'navigation_abort') return;
            return loadFromFallback(reason);
        });
    }
    var WCPOSLoader = { base64ToBytes: base64ToBytes, utf8Bytes: utf8Bytes,
        checkDescriptor: checkDescriptor, verifyDescriptor: verifyDescriptor, boot: boot };
    if (typeof module !== 'undefined') module.exports = WCPOSLoader;
    if (typeof window !== 'undefined') window.WCPOSLoader = WCPOSLoader;
    var defaultDeps;
    if (typeof window !== 'undefined') {
        var storage = null;
        try { storage = window.localStorage; } catch (_) {}
        defaultDeps = { fetch: window.fetch.bind(window), subtle: window.crypto && window.crypto.subtle,
            // Bound: a bare global called as deps.setTimeout(...) has deps as its receiver and throws in browsers.
            setTimeout: function (fn, ms) { return window.setTimeout(fn, ms); },
            getScript: getScript, loadCSS: loadCSS, storage: storage,
            sendBeacon: function (url, body) { return window.navigator.sendBeacon(url, body); },
            now: function () { return new Date(); }, setGlobalBase: function (url) { window.cdnBaseUrl = url; } };
    }
    if (typeof window !== 'undefined' && typeof initialProps !== 'undefined') WCPOSLoader.boot(initialProps.loader, defaultDeps);
}());
