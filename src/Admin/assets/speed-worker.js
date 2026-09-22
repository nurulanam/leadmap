/**
 * Background PageSpeed worker.
 *
 * Measurements are queued as background jobs, but WP-Cron spawns at most once a minute and
 * often dies part way through a batch, so a queue that should drain in seconds trickles out
 * over many minutes. While a LeadMap screen is open this turns the browser into the worker:
 * one measurement per call, the server's rate limiter still deciding the pace.
 *
 * It is deliberately quiet — a small status line, no interruption — because it runs while
 * the operator is doing something else.
 */
( function () {
	'use strict';

	if ( typeof window.leadmapWorker === 'undefined' ) {
		return;
	}

	var cfg     = window.leadmapWorker;
	var badge   = null;
	var stopped = false;
	var idle    = 0;

	function ensureBadge() {
		if ( badge ) {
			return badge;
		}

		badge = document.createElement( 'div' );
		badge.className = 'leadmap-worker';
		badge.hidden = true;
		document.body.appendChild( badge );

		return badge;
	}

	function show( pending ) {
		var el = ensureBadge();

		if ( pending < 1 ) {
			el.hidden = true;
			return;
		}

		el.hidden = false;
		el.textContent = cfg.i18n.working.replace( '%d', pending );
	}

	function tick() {
		if ( stopped || document.hidden ) {
			// Nothing useful happens in a background tab; check again shortly.
			window.setTimeout( tick, 5000 );
			return;
		}

		window.fetch( cfg.nextUrl, {
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin'
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'worker request failed' );
				}

				return response.json();
			} )
			.then( function ( data ) {
				show( data.pending || 0 );

				if ( data.idle ) {
					// Back off while there is nothing to do, rather than polling forever.
					idle++;
					window.setTimeout( tick, Math.min( 60000, 8000 * idle ) );
					return;
				}

				idle = 0;

				// A measurement takes most of a minute, so there is no point rushing the
				// next call; when the server says it is rate limited, wait exactly that long.
				window.setTimeout( tick, ( data.retry_in ? data.retry_in * 1000 : 1000 ) + 500 );
			} )
			.catch( function () {
				idle++;
				window.setTimeout( tick, Math.min( 60000, 10000 * idle ) );
			} );
	}

	// Let the page settle first — this is background work.
	window.setTimeout( tick, 3000 );

	window.addEventListener( 'beforeunload', function () {
		stopped = true;
	} );
} )();
