/**
 * Lead detail: screenshots, on-demand speed checks and triage from the same page.
 */
( function () {
	'use strict';

	if ( typeof window.leadmapLead === 'undefined' ) {
		return;
	}

	var cfg = window.leadmapLead;

	function post( url, body ) {
		return window.fetch( url, {
			method: 'POST',
			headers: {
				'X-WP-Nonce': cfg.nonce,
				'Content-Type': 'application/json'
			},
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( data && data.message ? data.message : cfg.i18n.failed );
				}

				return data;
			} );
		} );
	}

	// ---- Screenshots -------------------------------------------------------------------
	// These are files on our own disk, captured by Google during the PageSpeed run. The
	// button re-fetches them, which matters after a re-check has overwritten them: the URL
	// is unchanged, so without a fresh parameter the browser keeps the old image.
	var shots   = document.querySelector( '[data-leadmap-shots]' );
	var reshoot = shots ? shots.querySelector( '[data-leadmap-reshoot]' ) : null;

	if ( reshoot ) {
		var bust = 0;

		reshoot.addEventListener( 'click', function () {
			bust++;

			Array.prototype.forEach.call( shots.querySelectorAll( '[data-leadmap-shot]' ), function ( img ) {
				var base = img.src.split( /[?&]r=/ )[ 0 ];

				img.classList.add( 'is-loading' );
				img.src = base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + 'r=' + bust;

				img.addEventListener( 'load', function () {
					img.classList.remove( 'is-loading' );
				}, { once: true } );
			} );
		} );
	}

	// ---- Speed -------------------------------------------------------------------------
	var speed = document.querySelector( '[data-leadmap-speed]' );

	if ( speed ) {
		var leadId = speed.getAttribute( 'data-lead-id' );
		var button = speed.querySelector( '[data-leadmap-check-speed]' );
		var polling = false;

		function setGaugeState( strategy, text ) {
			var gauge = speed.querySelector( '[data-leadmap-gauge="' + strategy + '"]' );

			if ( ! gauge ) {
				return;
			}

			var label = gauge.querySelector( '[data-leadmap-gauge-state]' );

			if ( label ) {
				label.textContent = text;
			}
		}

		function poll() {
			if ( polling ) {
				return;
			}

			polling = true;

			window.fetch( cfg.speedStatusUrl.replace( '%d', leadId ), {
				headers: { 'X-WP-Nonce': cfg.nonce },
				credentials: 'same-origin'
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					polling = false;

					Object.keys( data.strategies || {} ).forEach( function ( strategy ) {
						setGaugeState( strategy, data.strategies[ strategy ].label );
					} );

					if ( data.running ) {
						window.setTimeout( poll, 5000 );
					} else {
						// Scores and the opportunity score both changed; reload to show them.
						window.location.reload();
					}
				} )
				.catch( function () {
					polling = false;
				} );
		}

		if ( button ) {
			button.addEventListener( 'click', function () {
				button.disabled = true;
				button.textContent = cfg.i18n.measuring;
				setGaugeState( 'mobile', cfg.i18n.measuring );
				setGaugeState( 'desktop', cfg.i18n.queued );

				post( cfg.speedUrl.replace( '%d', leadId ) )
					.then( function () {
						poll();
					} )
					.catch( function ( error ) {
						button.disabled = false;
						button.textContent = cfg.i18n.checkAgain;
						setGaugeState( 'mobile', error.message || cfg.i18n.failed );
					} );
			} );
		}

		// If something is already in the queue, follow it without being asked.
		if ( speed.querySelector( '.leadmap-gauge__ring--empty.is-pending' ) ) {
			window.setTimeout( poll, 5000 );
		}
	}

	// ---- Triage ------------------------------------------------------------------------
	var triage = document.querySelector( '[data-leadmap-triage]' );

	if ( triage ) {
		var tid    = triage.getAttribute( 'data-lead-id' );
		var status = triage.querySelector( '[data-leadmap-triage-status]' );

		triage.addEventListener( 'click', function ( event ) {
			var flag = event.target.closest( '[data-verdict]' );
			var term = event.target.closest( '[data-terminal]' );
			var save = event.target.closest( '[data-leadmap-triage-save]' );

			if ( flag ) {
				var on = flag.getAttribute( 'aria-pressed' ) === 'true';
				flag.setAttribute( 'aria-pressed', on ? 'false' : 'true' );
				flag.classList.toggle( 'is-on', ! on );

				// A pitchable flag and a terminal verdict are mutually exclusive.
				Array.prototype.forEach.call( triage.querySelectorAll( '[data-terminal]' ), function ( el ) {
					el.classList.remove( 'is-on' );
				} );

				return;
			}

			if ( term ) {
				Array.prototype.forEach.call( triage.querySelectorAll( '[data-terminal]' ), function ( el ) {
					el.classList.toggle( 'is-on', el === term );
				} );
				Array.prototype.forEach.call( triage.querySelectorAll( '[data-verdict]' ), function ( el ) {
					el.classList.remove( 'is-on' );
					el.setAttribute( 'aria-pressed', 'false' );
				} );

				return;
			}

			if ( ! save ) {
				return;
			}

			var chosen = Array.prototype.map.call(
				triage.querySelectorAll( '[data-terminal].is-on' ),
				function ( el ) {
					return el.getAttribute( 'data-terminal' );
				}
			);

			if ( ! chosen.length ) {
				chosen = Array.prototype.map.call(
					triage.querySelectorAll( '[data-verdict].is-on' ),
					function ( el ) {
						return el.getAttribute( 'data-verdict' );
					}
				);
			}

			if ( ! chosen.length ) {
				status.textContent = cfg.i18n.pickOne;
				status.className = 'leadmap-triage-panel__status is-error';
				return;
			}

			save.disabled = true;
			status.textContent = cfg.i18n.saving;
			status.className = 'leadmap-triage-panel__status';

			post( cfg.triageUrl.replace( '%d', tid ), { flags: chosen } )
				.then( function ( data ) {
					save.disabled = false;
					status.textContent = cfg.i18n.savedAs.replace( '%s', data.label );
					status.className = 'leadmap-triage-panel__status is-ok';

					var current = triage.querySelector( '[data-leadmap-triage-current]' );

					if ( current ) {
						current.textContent = data.label;
					}
				} )
				.catch( function ( error ) {
					save.disabled = false;
					status.textContent = error.message || cfg.i18n.failed;
					status.className = 'leadmap-triage-panel__status is-error';
				} );
		} );
	}
} )();
