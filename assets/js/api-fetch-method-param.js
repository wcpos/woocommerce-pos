/**
 * Route api-fetch PUT/PATCH/DELETE through POST + `?_method=<VERB>`.
 *
 * `@wordpress/api-fetch` rewrites PUT/PATCH/DELETE into a POST that carries an
 * `X-HTTP-Method-Override` header (its httpV1 middleware). CRS-default WAFs and
 * some managed hosts 403 both the bare verbs and that header before WordPress
 * sees the request, so every wp-admin mutation in a WCPOS bundle would die on
 * such a host. WordPress core reads the `_method` query parameter BEFORE the
 * override header (WP_REST_Server::serve_request), so a plain POST with
 * `?_method=DELETE` reaches the same route handler on every host.
 *
 * The POS client already made this exact switch (mono#1397); this shim brings
 * the wp-admin bundles in line. `apiFetch.use()` unshifts, so this runs before
 * the httpV1 middleware, which then sees a POST and adds nothing.
 *
 * Loaded once per admin page via the `wcpos-api-fetch-method-param` script
 * handle; the shared `wp.apiFetch` instance is patched, so every bundle that
 * lists the handle as a dependency is covered.
 */
( function ( wp ) {
	if ( ! wp || ! wp.apiFetch || wp.apiFetch.wcposMethodParam ) {
		return;
	}

	var REWRITTEN_METHODS = { PUT: true, PATCH: true, DELETE: true };

	// Only WCPOS routes are rewritten. The shared instance also serves core
	// calls on these screens, and core's own middlewares (media uploads, for
	// one) key off the verb — a rewritten `DELETE /wp/v2/media/{id}` would be
	// mistaken for an upload.
	var WCPOS_ROUTE = /(^|\/)wcpos\/v\d+\//;

	wp.apiFetch.use( function ( options, next ) {
		var method = String( options.method || 'GET' ).toUpperCase();

		if ( ! REWRITTEN_METHODS[ method ] ) {
			return next( options );
		}

		// api-fetch accepts either a REST `path` or an absolute `url`. A
		// `namespace` + `endpoint` caller is left alone: core assembles its
		// `path` AFTER this middleware and would drop the `_method` param.
		var key = typeof options.url === 'string' ? 'url' : 'path';
		var target = options[ key ];

		if ( typeof target !== 'string' || ! WCPOS_ROUTE.test( target ) ) {
			return next( options );
		}

		var separator = target.indexOf( '?' ) === -1 ? '?' : '&';
		var rewritten = Object.assign( {}, options, { method: 'POST' } );

		rewritten[ key ] = target + separator + '_method=' + method;

		return next( rewritten );
	} );

	wp.apiFetch.wcposMethodParam = true;
} )( window.wp );
