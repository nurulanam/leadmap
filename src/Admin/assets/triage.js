/**
 * Triage grid interaction.
 *
 * Throughput is the whole point of this screen, so nothing here waits on the network: a
 * verdict updates the card and moves focus on immediately, and the request settles in the
 * background. If it fails the card comes back with the reason rather than being lost.
 */
( function () {
	'use strict';

	var grid = document.querySelector( '[data-leadmap-grid]' );

	if ( ! grid || typeof window.leadmapTriage === 'undefined' ) {
		return;
	}

	var cfg   = window.leadmapTriage;
	var toast = document.querySelector( '[data-leadmap-toast]' );
	var lastDecision = null;

	function cards() {
		return Array.prototype.slice.call( grid.querySelectorAll( '[data-leadmap-card]' ) );
	}

	function current() {
		return grid.querySelector( '.is-current' );
	}

	function focusCard( card ) {
		if ( ! card ) {
			return;
		}

		cards().forEach( function ( el ) {
			el.classList.remove( 'is-current' );
		} );

		card.classList.add( 'is-current' );
		card.focus( { preventScroll: true } );
		card.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
	}

	function move( offset ) {
		var all = cards();
		var idx = all.indexOf( current() );

		if ( idx === -1 ) {
			focusCard( all[ 0 ] );
			return;
		}

		focusCard( all[ Math.max( 0, Math.min( all.length - 1, idx + offset ) ) ] );
	}

	/** Advance to the next card that has not been decided yet. */
	function advance( from ) {
		var all = cards();
		var idx = all.indexOf( from );

		for ( var i = idx + 1; i < all.length; i++ ) {
			if ( ! all[ i ].classList.contains( 'is-done' ) ) {
				focusCard( all[ i ] );
				return;
			}
		}

		for ( var j = 0; j < idx; j++ ) {
			if ( ! all[ j ].classList.contains( 'is-done' ) ) {
				focusCard( all[ j ] );
				return;
			}
		}

		// Every card on this page is decided — bring in the next batch.
		if ( ! grid.querySelector( '[data-leadmap-card]:not(.is-done)' ) ) {
			window.setTimeout( function () {
				window.location.reload();
			}, 600 );
		}
	}

	function toggleFlag( card, verdict ) {
		var button = card.querySelector( '[data-verdict="' + verdict + '"]' );

		if ( ! button ) {
			return;
		}

		var on = button.getAttribute( 'aria-pressed' ) === 'true';
		button.setAttribute( 'aria-pressed', on ? 'false' : 'true' );
		button.classList.toggle( 'is-on', ! on );
	}

	function selectedFlags( card ) {
		return Array.prototype.map.call(
			card.querySelectorAll( '[data-verdict].is-on' ),
			function ( el ) {
				return el.getAttribute( 'data-verdict' );
			}
		);
	}

	function showToast( text, undoable ) {
		if ( ! toast ) {
			return;
		}

		toast.querySelector( '[data-leadmap-toast-text]' ).textContent = text;
		toast.querySelector( '[data-leadmap-undo]' ).hidden = ! undoable;
		toast.hidden = false;

		window.clearTimeout( toast.timer );
		toast.timer = window.setTimeout( function () {
			toast.hidden = true;
		}, 6000 );
	}

	function decrementRemaining( by ) {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-leadmap-remaining]' ),
			function ( el ) {
				var n = parseInt( el.textContent.replace( /[^0-9]/g, '' ), 10 );

				if ( ! isNaN( n ) ) {
					el.textContent = Math.max( 0, n + by ).toLocaleString();
				}
			}
		);
	}

	function decide( card, flags ) {
		if ( ! card || ! flags.length || card.classList.contains( 'is-saving' ) ) {
			return;
		}

		var id = card.getAttribute( 'data-lead-id' );

		// Optimistic: the operator moves on now, the request settles behind them.
		card.classList.add( 'is-done', 'is-saving' );
		card.setAttribute( 'data-verdict-applied', flags.join( ',' ) );
		decrementRemaining( -1 );
		advance( card );

		window.fetch( cfg.decideUrl.replace( '%d', id ), {
			method: 'POST',
			headers: {
				'X-WP-Nonce': cfg.nonce,
				'Content-Type': 'application/json'
			},
			credentials: 'same-origin',
			body: JSON.stringify( { flags: flags } )
		} )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					if ( ! response.ok ) {
						throw new Error( body && body.message ? body.message : cfg.i18n.failed );
					}

					return body;
				} );
			} )
			.then( function ( data ) {
				card.classList.remove( 'is-saving' );
				lastDecision = id;
				showToast( cfg.i18n.saved.replace( '%s', data.label || flags.join( ', ' ) ), true );
			} )
			.catch( function ( error ) {
				// Put it back rather than losing the lead silently.
				card.classList.remove( 'is-done', 'is-saving' );
				card.classList.add( 'is-error' );
				decrementRemaining( 1 );
				showToast( error.message || cfg.i18n.failed, false );
			} );
	}

	function undo() {
		if ( ! lastDecision ) {
			return;
		}

		var id = lastDecision;
		lastDecision = null;

		window.fetch( cfg.undoUrl.replace( '%d', id ), {
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin'
		} )
			.then( function () {
				var card = grid.querySelector( '[data-lead-id="' + id + '"]' );

				if ( card ) {
					card.classList.remove( 'is-done' );
					card.removeAttribute( 'data-verdict-applied' );
					Array.prototype.forEach.call(
						card.querySelectorAll( '[data-verdict].is-on' ),
						function ( el ) {
							el.classList.remove( 'is-on' );
							el.setAttribute( 'aria-pressed', 'false' );
						}
					);
					focusCard( card );
				}

				decrementRemaining( 1 );
				showToast( cfg.i18n.undone, false );
			} );
	}

	// ---- Mouse -------------------------------------------------------------------------
	grid.addEventListener( 'click', function ( event ) {
		var card = event.target.closest( '[data-leadmap-card]' );

		if ( ! card ) {
			return;
		}

		var flag = event.target.closest( '[data-verdict]' );
		var term = event.target.closest( '[data-terminal]' );
		var save = event.target.closest( '[data-leadmap-save]' );

		if ( flag ) {
			focusCard( card );
			toggleFlag( card, flag.getAttribute( 'data-verdict' ) );
			return;
		}

		if ( term ) {
			decide( card, [ term.getAttribute( 'data-terminal' ) ] );
			return;
		}

		if ( save ) {
			decide( card, selectedFlags( card ) );
			return;
		}

		focusCard( card );
	} );

	if ( toast ) {
		toast.querySelector( '[data-leadmap-undo]' ).addEventListener( 'click', undo );
	}

	// ---- Keyboard ----------------------------------------------------------------------
	var flagKeys = {
		'1': 'outdated',
		'2': 'broken',
		'3': 'not_mobile',
		'4': 'slow',
		'5': 'no_ssl',
		'6': 'poor_seo',
		'7': 'weak_gmb'
	};
	var termKeys = { s: 'skip', n: 'no_website', u: 'unsure' };

	document.addEventListener( 'keydown', function ( event ) {
		var tag = ( event.target.tagName || '' ).toLowerCase();

		if ( tag === 'input' || tag === 'textarea' || event.target.isContentEditable ) {
			return;
		}

		if ( ( event.ctrlKey || event.metaKey ) && event.key.toLowerCase() === 'z' ) {
			event.preventDefault();
			undo();
			return;
		}

		if ( event.altKey || event.ctrlKey || event.metaKey ) {
			return;
		}

		var card = current();

		if ( ! card ) {
			return;
		}

		if ( flagKeys[ event.key ] ) {
			event.preventDefault();
			toggleFlag( card, flagKeys[ event.key ] );
			return;
		}

		var lower = event.key.toLowerCase();

		if ( termKeys[ lower ] ) {
			event.preventDefault();
			decide( card, [ termKeys[ lower ] ] );
			return;
		}

		// Accept everything the automated checks pointed at, then the operator adjusts.
		if ( lower === 'a' ) {
			event.preventDefault();

			Array.prototype.forEach.call(
				card.querySelectorAll( '[data-suggested="1"]' ),
				function ( el ) {
					el.classList.add( 'is-on' );
					el.setAttribute( 'aria-pressed', 'true' );
				}
			);

			return;
		}

		if ( event.key === 'Enter' ) {
			event.preventDefault();
			decide( card, selectedFlags( card ) );
			return;
		}

		if ( event.key === 'ArrowRight' || event.key === 'ArrowDown' ) {
			event.preventDefault();
			move( 1 );
		}

		if ( event.key === 'ArrowLeft' || event.key === 'ArrowUp' ) {
			event.preventDefault();
			move( -1 );
		}
	} );

	// mShots generates on first request, so an early hit can return a placeholder.
	Array.prototype.forEach.call( document.querySelectorAll( '[data-leadmap-shot]' ), function ( img ) {
		var tries = 0;

		img.addEventListener( 'error', function () {
			if ( tries >= 2 ) {
				img.closest( '.leadmap-shot' ).classList.add( 'has-no-shot' );
				return;
			}

			tries++;
			var src = img.src;

			window.setTimeout( function () {
				img.src = src + ( src.indexOf( '?' ) === -1 ? '?' : '&' ) + 'retry=' + tries;
			}, 2500 * tries );
		} );
	} );

	// Shortcuts help
	var helpToggle = document.querySelector( '.leadmap-triage__help-toggle' );
	var help       = document.querySelector( '.leadmap-triage__help' );

	if ( helpToggle && help ) {
		helpToggle.addEventListener( 'click', function () {
			var open = ! help.hidden;
			help.hidden = open;
			helpToggle.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
		} );
	}

	focusCard( cards()[ 0 ] );
} )();
