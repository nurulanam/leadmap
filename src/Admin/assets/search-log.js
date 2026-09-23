/**
 * Live search console.
 *
 * A search runs as a chain of background jobs, so the screen would otherwise sit still for
 * a minute with nothing to look at. This polls the progress endpoint and streams what the
 * runner is actually doing, then stops polling the moment the search settles.
 */
( function () {
	'use strict';

	var panel = document.getElementById( 'leadmap-live' );

	if ( ! panel || typeof window.leadmapLive === 'undefined' ) {
		return;
	}

	var cfg     = window.leadmapLive;
	var output  = panel.querySelector( '.leadmap-live__log' );
	var bar     = panel.querySelector( '.leadmap-live__bar span' );
	var stats   = panel.querySelector( '.leadmap-live__stats' );
	var heading = panel.querySelector( '.leadmap-live__state' );
	var done    = panel.querySelector( '.leadmap-live__done' );

	// The server already rendered the log it had, so start from what is on the page.
	// Starting from zero made the first poll append every existing line a second time.
	var rendered = output ? output.querySelectorAll( '.leadmap-live__line' ).length : 0;
	var delay    = 2000;
	var failures = 0;
	var stopped  = false;

	function line( entry ) {
		var row = document.createElement( 'div' );
		row.className = 'leadmap-live__line is-' + entry.level;

		var time = document.createElement( 'span' );
		time.className = 'leadmap-live__time';
		time.textContent = entry.time;

		var text = document.createElement( 'span' );
		text.textContent = entry.message;

		row.appendChild( time );
		row.appendChild( text );

		return row;
	}

	function render( data ) {
		// Only append what is new, so the console does not flicker or lose scroll position.
		if ( output && data.log.length > rendered ) {
			var atBottom = output.scrollHeight - output.scrollTop - output.clientHeight < 40;

			for ( var i = rendered; i < data.log.length; i++ ) {
				output.appendChild( line( data.log[ i ] ) );
			}

			rendered = data.log.length;

			if ( atBottom ) {
				output.scrollTop = output.scrollHeight;
			}
		}

		if ( stats ) {
			stats.textContent = cfg.i18n.stats
				.replace( '%1$s', data['new'] )
				.replace( '%2$s', data.found )
				.replace( '%3$s', data.cost.toFixed( 2 ) );
		}

		if ( bar ) {
			// Results against the cap is the only honest progress we have; Google does not
			// say how many pages remain.
			var pct = data.max > 0 ? Math.min( 100, Math.round( ( data.found / data.max ) * 100 ) ) : 0;

			bar.style.width = ( data.running ? Math.max( 5, pct ) : 100 ) + '%';
			bar.parentNode.classList.toggle( 'is-done', ! data.running );
		}

		if ( heading ) {
			if ( data.running && data.stalled ) {
				// Running, but nothing has happened for a while — say so rather than
				// spinning indefinitely with no explanation.
				heading.textContent = cfg.i18n.stalled;
			} else if ( data.running ) {
				heading.textContent = cfg.i18n.running;
			} else if ( data.status === 'failed' ) {
				heading.textContent = cfg.i18n.failed;
			} else if ( data.status === 'cancelled' ) {
				heading.textContent = cfg.i18n.stopped;
			} else {
				heading.textContent = cfg.i18n.complete;
			}
		}

		var stopBtn = panel.querySelector( '.leadmap-live__stop' );

		if ( stopBtn ) {
			stopBtn.hidden = ! data.running;
		}

		panel.classList.toggle( 'is-running', data.running );
		panel.classList.toggle( 'is-failed', data.status === 'failed' );
		panel.classList.toggle( 'is-stopped', data.status === 'cancelled' );

		var hint = panel.querySelector( '.leadmap-live__hint' );

		if ( hint ) {
			hint.hidden = ! data.running;
		}

		if ( ! data.running && done ) {
			done.hidden = false;

			var link = done.querySelector( 'a' );

			if ( link && data['new'] > 0 ) {
				link.href = data.leads_url;
				link.hidden = false;
			}
		}
	}

	function poll() {
		window.fetch( cfg.progressUrl, {
			headers: { 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin'
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'progress request failed' );
				}

				return response.json();
			} )
			.then( function ( data ) {
				failures = 0;
				render( data );

				if ( data.running ) {
					window.setTimeout( poll, delay );
				}
			} )
			.catch( function () {
				// Back off rather than hammering a site that is already struggling, and
				// give up quietly instead of looping forever.
				failures++;

				if ( failures < 5 ) {
					delay = Math.min( 15000, delay * 2 );
					window.setTimeout( poll, delay );
				} else if ( heading ) {
					heading.textContent = cfg.i18n.lost;
				}
			} );
	}

	// ---- Stop ------------------------------------------------------------------------
	var stopButton = panel.querySelector( '.leadmap-live__stop' );

	if ( stopButton ) {
		stopButton.addEventListener( 'click', function () {
			if ( stopped || ! window.confirm( cfg.i18n.confirmStop ) ) {
				return;
			}

			stopped = true;
			stopButton.disabled = true;
			stopButton.textContent = cfg.i18n.stopping;

			window.fetch( cfg.stopUrl, {
				method: 'POST',
				headers: { 'X-WP-Nonce': cfg.nonce },
				credentials: 'same-origin'
			} )
				.then( function () {
					// The next poll reflects the new status; force one immediately.
					poll();
				} )
				.catch( function () {
					stopped = false;
					stopButton.disabled = false;
					stopButton.textContent = cfg.i18n.stop;
				} );
		} );
	}

	// ---- Modal behaviour -------------------------------------------------------------
	var modal = document.getElementById( 'leadmap-live-modal' );

	if ( modal ) {
		var closeUrl = modal.getAttribute( 'data-close-url' ) || '';
		var opener   = document.activeElement;

		function close( event ) {
			if ( event ) {
				event.preventDefault();
			}

			modal.parentNode.removeChild( modal );
			document.body.classList.remove( 'leadmap-modal-open' );

			// Drop ?watch= so a reload does not reopen it, without losing the page.
			if ( window.history && window.history.replaceState && closeUrl ) {
				window.history.replaceState( {}, '', closeUrl );
			}

			if ( opener && opener.focus ) {
				opener.focus();
			}
		}

		document.body.classList.add( 'leadmap-modal-open' );

		Array.prototype.forEach.call(
			modal.querySelectorAll( '[data-leadmap-close]' ),
			function ( el ) {
				el.addEventListener( 'click', close );
			}
		);

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && document.body.contains( modal ) ) {
				close( event );
			}
		} );

		// Keep tabbing inside the dialog while it is open.
		modal.addEventListener( 'keydown', function ( event ) {
			if ( event.key !== 'Tab' ) {
				return;
			}

			var focusable = modal.querySelectorAll( 'a[href], button, [tabindex]:not([tabindex="-1"])' );

			if ( ! focusable.length ) {
				return;
			}

			var first = focusable[ 0 ];
			var last  = focusable[ focusable.length - 1 ];

			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		} );

		var closeButton = modal.querySelector( '.leadmap-modal__close' );

		if ( closeButton ) {
			closeButton.focus();
		}
	}

	poll();
} )();
