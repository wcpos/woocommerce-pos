JS assets are compiled during CI, except vendored storage assets.

`sqlite.worker.js` and `sqlite3.wasm` are copied together from the matching
web-bundle build. Keep them side by side: the worker fetches `sqlite3.wasm`
relative to its own URL, preserving the cache-version query.
