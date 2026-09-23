/**
 * Audit form.
 *
 * Keyboard-first, because this screen is worked through in a sitting: number keys toggle
 * problems, Ctrl+Enter saves and loads the next lead. The form never navigates away on its
 * own without saving — the note is the most expensive thing on the page.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-leadmap-audit]' );

	if ( ! root || typeof window.leadmapAudit === 'undefined' ) {
		return;
	}

	var cfg    = window.leadmapAudit;
	var form   = root.querySelector( '[data-leadmap-audit-form]' );
	var status = root.querySelector( '[data-leadmap-audit-status]' );
	var leadId = root.getAttribute( 'data-lead-id' );
	var notes  = form.querySelector( '#lm-notes' );
	var primary = form.querySelector( '#lm-primary' );
	var saving = false;

	function say( text, kind ) {
		status.textContent = text;
		status.className = 'leadmap-audit__status' + ( kind ? ' is-' + kind : '' );
	}

	function chosenTags() {
		return Array.prototype.map.call(
			form.querySelectorAll( 'input[name="issue_tags[]"]:checked' ),
			function ( el ) {
				return el.value;
			}
		);
	}

	function syncLabels() {
		Array.prototype.forEach.call( form.querySelectorAll( '.leadmap-audit__tags label' ), function ( label ) {
			var box = label.querySelector( 'input' );
			label.classList.toggle( 'is-on', box.checked );

			if ( box.checked ) {
				label.classList.remove( 'is-suggested' );
			}
		} );
	}

	/** Offer only problems that were actually ticked — leading with an untagged one is a slip. */
	function syncPrimary() {
		var tags = chosenTags();
		var current = primary.value;

		Array.prototype.forEach.call( primary.options, function ( option ) {
			if ( '' === option.value ) {
				return;
			}

			option.hidden = tags.indexOf( option.value ) === -1;
		} );

		if ( current && tags.indexOf( current ) === -1 ) {
			primary.value = '';
		}

		// One problem means there is nothing to choose.
		if ( ! primary.value && tags.length === 1 ) {
			primary.value = tags[ 0 ];
		}
	}

	form.addEventListener( 'change', function ( event ) {
		if ( event.target.name === 'issue_tags[]' ) {
			syncLabels();
			syncPrimary();
		}
	} );

	function submit( outcome ) {
		if ( saving ) {
			return;
		}

		saving = true;
		say( cfg.i18n.saving );

		var body = {
			outcome: outcome,
			issue_tags: chosenTags(),
			primary_issue: primary.value,
			problem_notes: notes.value,
			is_reachable: form.querySelector( 'input[name="is_reachable"]' ).checked,
			fit_override: form.querySelector( '#lm-fit-override' ).value
		};

		window.fetch( cfg.saveUrl.replace( '%d', leadId ), {
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify( body )
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error( data && data.message ? data.message : cfg.i18n.failed );
					}

					return data;
				} );
			} )
			.then( function () {
				say( cfg.i18n.saved, 'ok' );
				window.location.href = cfg.nextUrl;
			} )
			.catch( function ( error ) {
				saving = false;
				say( error.message || cfg.i18n.failed, 'error' );

				// Put the cursor where the problem almost always is.
				if ( /sentence|problem/i.test( error.message || '' ) ) {
					notes.focus();
				}
			} );
	}

	// ---- Draft -------------------------------------------------------------------------
	var draftButton = form.querySelector( '[data-leadmap-draft]' );

	if ( draftButton ) {
		draftButton.addEventListener( 'click', function () {
			var tags = chosenTags();

			if ( ! tags.length ) {
				say( cfg.i18n.draftNeedsTags, 'error' );
				return;
			}

			// Never quietly replace something the operator wrote.
			if ( notes.value.trim() !== '' && ! window.confirm( cfg.i18n.draftOverwrite ) ) {
				return;
			}

			draftButton.disabled = true;
			say( cfg.i18n.drafting );

			window.fetch( cfg.draftUrl.replace( '%d', leadId ), {
				method: 'POST',
				headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify( { issue_tags: tags, primary_issue: primary.value } )
			} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						if ( ! response.ok ) {
							throw new Error( data && data.message ? data.message : cfg.i18n.failed );
						}

						return data;
					} );
				} )
				.then( function ( data ) {
					draftButton.disabled = false;
					notes.value = data.text;
					notes.focus();
					notes.setSelectionRange( notes.value.length, notes.value.length );
					say( cfg.i18n.drafted, 'ok' );
				} )
				.catch( function ( error ) {
					draftButton.disabled = false;
					say( error.message || cfg.i18n.failed, 'error' );
				} );
		} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		submit( 'audited' );
	} );

	root.querySelector( '[data-leadmap-not-a-fit]' ).addEventListener( 'click', function () {
		if ( window.confirm( cfg.i18n.confirmNotAFit ) ) {
			submit( 'not_a_fit' );
		}
	} );

	// Warn before losing a note that has not been saved.
	var pristine = notes.value;

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( ! saving && notes.value !== pristine && notes.value.trim() !== '' ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	var tagKeys = {};

	Array.prototype.forEach.call( form.querySelectorAll( '.leadmap-audit__tags label' ), function ( label ) {
		var key = label.querySelector( 'kbd' );
		var box = label.querySelector( 'input' );

		if ( key && box ) {
			tagKeys[ key.textContent.trim() ] = box;
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( ( event.ctrlKey || event.metaKey ) && event.key === 'Enter' ) {
			event.preventDefault();
			submit( 'audited' );
			return;
		}

		// Number keys are for toggling problems, not for typing into the note.
		var tag = ( event.target.tagName || '' ).toLowerCase();

		if ( tag === 'input' || tag === 'textarea' || tag === 'select' || event.target.isContentEditable ) {
			return;
		}

		if ( event.altKey || event.ctrlKey || event.metaKey ) {
			return;
		}

		if ( tagKeys[ event.key ] ) {
			event.preventDefault();
			tagKeys[ event.key ].checked = ! tagKeys[ event.key ].checked;
			syncLabels();
			syncPrimary();
		}
	} );

	syncLabels();
	syncPrimary();
	notes.focus();
} )();
