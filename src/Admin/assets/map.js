/**
 * Search area preview.
 *
 * Draws the city/ZIP the operator typed, with the radius they picked, so the area being
 * searched is visible before any money is spent. Geocoding is proxied through the plugin's
 * own REST route because the Google key is IP-restricted and cannot be used from a browser.
 */
( function () {
	'use strict';

	var el = document.getElementById( 'leadmap-map' );

	if ( ! el || typeof L === 'undefined' || typeof window.leadmapMap === 'undefined' ) {
		return;
	}

	var cfg      = window.leadmapMap;
	var status   = document.getElementById( 'leadmap-map-status' );
	var location = document.getElementById( 'lm-location' );
	var zip      = document.getElementById( 'lm-zip' );
	var radius   = document.getElementById( 'lm-radius' );

	var map = L.map( el, {
		zoomControl: true,
		scrollWheelZoom: false, // Scrolling the page over a map should scroll the page.
		attributionControl: true
	} ).setView( [ cfg.lat, cfg.lng ], 11 );

	L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
		maxZoom: 18,
		attribution: '&copy; OpenStreetMap'
	} ).addTo( map );

	var circle = null;
	var pin    = null;
	var timer  = null;
	var lastQuery = '';

	function setStatus( text, kind ) {
		if ( ! status ) {
			return;
		}

		status.textContent = text;
		status.className = 'leadmap-map__status' + ( kind ? ' is-' + kind : '' );
	}

	function metres() {
		return radius ? parseInt( radius.value, 10 ) || 5000 : 5000;
	}

	function draw( lat, lng ) {
		if ( circle ) {
			map.removeLayer( circle );
		}

		if ( pin ) {
			map.removeLayer( pin );
		}

		circle = L.circle( [ lat, lng ], {
			radius: metres(),
			color: '#2271b1',
			weight: 2,
			fillColor: '#2271b1',
			fillOpacity: 0.12
		} ).addTo( map );

		// A plain SVG dot, so no marker image files are needed.
		pin = L.circleMarker( [ lat, lng ], {
			radius: 5,
			color: '#fff',
			weight: 2,
			fillColor: '#d63638',
			fillOpacity: 1
		} ).addTo( map );

		map.fitBounds( circle.getBounds(), { padding: [ 20, 20 ] } );
	}

	function lookup() {
		var query = [ ( zip && zip.value ) || '', ( location && location.value ) || '' ]
			.join( ' ' ).trim();

		if ( query === '' ) {
			setStatus( cfg.i18n.prompt, '' );
			return;
		}

		if ( query === lastQuery && circle ) {
			// Location unchanged; only the radius moved.
			circle.setRadius( metres() );
			map.fitBounds( circle.getBounds(), { padding: [ 20, 20 ] } );
			setStatus( summary(), 'ok' );
			return;
		}

		setStatus( cfg.i18n.locating, 'busy' );

		var url = cfg.geocodeUrl +
			'?location=' + encodeURIComponent( ( location && location.value ) || '' ) +
			'&zip=' + encodeURIComponent( ( zip && zip.value ) || '' );

		window.fetch( url, {
			headers: { 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin'
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
				lastQuery = query;
				draw( data.lat, data.lng );
				setStatus( summary(), 'ok' );
			} )
			.catch( function ( error ) {
				lastQuery = '';
				setStatus( error.message || cfg.i18n.failed, 'error' );
			} );
	}

	function summary() {
		var km = ( metres() / 1000 ).toFixed( 1 );

		return cfg.i18n.showing.replace( '%s', km );
	}

	function debounced() {
		window.clearTimeout( timer );
		timer = window.setTimeout( lookup, 700 );
	}

	if ( location ) {
		location.addEventListener( 'input', debounced );
	}

	if ( zip ) {
		zip.addEventListener( 'input', debounced );
	}

	if ( radius ) {
		radius.addEventListener( 'change', lookup );
	}

	// Leaflet mis-measures a container that was hidden or resized during load.
	window.setTimeout( function () {
		map.invalidateSize();
	}, 200 );

	setStatus( cfg.i18n.prompt, '' );

	if ( ( zip && zip.value ) || ( location && location.value ) ) {
		lookup();
	}
} )();
