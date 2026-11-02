/**
 * WPCalendarCats front end.
 *
 * Reads events from the plugin's REST route and renders the card view,
 * calendar grid, search results, category filters and event popups.
 * Deliberately dependency-free — it has to drop into any theme without
 * dragging a framework along.
 */
( function () {
	'use strict';

	var DOW = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
	// Cards are sized for this many event rows; 5 or more events scroll.
	// If changed, update .wpcc-card-list.is-scrollable max-height in wpcc.css
	// (one row is ~68px).
	var MAX_VISIBLE_ROWS = 4;

	// Content zoom, matching the hosted calendar's slider. Applied as CSS
	// zoom on the results body only, so the toolbar itself stays put and the
	// control never scales itself out from under the pointer.
	var ZOOM_MIN = 75;
	var ZOOM_MAX = 150;
	var ZOOM_STEP = 5;
	var ZOOM_DEFAULT = 100;

	/* ── Contrast helpers ───────────────────────────────────────────────
	 * Category colours are admin-editable data, not design tokens, so no
	 * fixed palette can guarantee they are legible when painted as text.
	 * Anything that uses a category colour as *text* gets a corrected
	 * variant computed against the surface it actually sits on; borders and
	 * backgrounds keep the raw colour.
	 *
	 * The surface each theme paints behind that text.
	 */
	var SURFACE = {
		light: '#ffffff',
		dark: '#0d3340'
	};

	function hexToRgb( hex ) {
		if ( 'string' !== typeof hex ) {
			return null;
		}
		var h = hex.replace( '#', '' ).trim();
		if ( 3 === h.length ) {
			h = h.charAt( 0 ) + h.charAt( 0 ) + h.charAt( 1 ) + h.charAt( 1 ) + h.charAt( 2 ) + h.charAt( 2 );
		}
		if ( ! /^[0-9a-fA-F]{6}$/.test( h ) ) {
			return null;
		}
		return [
			parseInt( h.slice( 0, 2 ), 16 ),
			parseInt( h.slice( 2, 4 ), 16 ),
			parseInt( h.slice( 4, 6 ), 16 )
		];
	}

	/** WCAG relative luminance. */
	function luminance( rgb ) {
		var out = [];
		for ( var i = 0; i < 3; i++ ) {
			var c = rgb[ i ] / 255;
			out.push( c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 ) );
		}
		return 0.2126 * out[ 0 ] + 0.7152 * out[ 1 ] + 0.0722 * out[ 2 ];
	}

	function contrast( a, b ) {
		var la = luminance( a );
		var lb = luminance( b );
		return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
	}

	function toHex( rgb ) {
		var s = '#';
		for ( var i = 0; i < 3; i++ ) {
			var v = Math.max( 0, Math.min( 255, Math.round( rgb[ i ] ) ) ).toString( 16 );
			s += 1 === v.length ? '0' + v : v;
		}
		return s;
	}

	/**
	 * Walk a colour toward black or white until it clears `target` on `bg`.
	 *
	 * @param {string} color  Candidate colour, hex.
	 * @param {string} bg     Background it will sit on, hex.
	 * @param {number} target Required contrast ratio.
	 * @return {string} The original colour when it already passes.
	 */
	function readableOn( color, bg, target ) {
		var rgb = hexToRgb( color );
		var bgRgb = hexToRgb( bg );

		if ( ! rgb || ! bgRgb ) {
			return color;
		}
		if ( contrast( rgb, bgRgb ) >= target ) {
			return color;
		}

		// Light surface: darken. Dark surface: lighten.
		var toward = luminance( bgRgb ) > 0.4 ? 0 : 255;

		for ( var i = 1; i <= 40; i++ ) {
			var k = i / 40;
			var mix = [
				rgb[ 0 ] + ( toward - rgb[ 0 ] ) * k,
				rgb[ 1 ] + ( toward - rgb[ 1 ] ) * k,
				rgb[ 2 ] + ( toward - rgb[ 2 ] ) * k
			];
			if ( contrast( mix, bgRgb ) >= target ) {
				return toHex( mix );
			}
		}

		return 0 === toward ? '#000000' : '#ffffff';
	}

	/**
	 * Pick a foreground to sit ON a known background.
	 *
	 * The inverse of readableOn(): here the background is the fixed thing (a
	 * category colour) and the text has to adapt. White is preferred so the
	 * badges keep their look, and where white is too weak the background
	 * colour itself is darkened toward black, which keeps the badge on-brand
	 * instead of dropping to a flat black. Pure black always clears 4.54:1
	 * whenever white fails, so this cannot run out of room.
	 *
	 * @param {string} bg     Background colour, hex.
	 * @param {number} target Required contrast ratio.
	 * @return {string} Hex colour for the text.
	 */
	function inkOn( bg, target ) {
		var bgRgb = hexToRgb( bg );

		if ( ! bgRgb ) {
			return '#ffffff';
		}
		if ( contrast( [ 255, 255, 255 ], bgRgb ) >= target ) {
			return '#ffffff';
		}

		for ( var i = 1; i <= 40; i++ ) {
			var k = i / 40;
			var mix = [
				bgRgb[ 0 ] * ( 1 - k ),
				bgRgb[ 1 ] * ( 1 - k ),
				bgRgb[ 2 ] * ( 1 - k )
			];
			if ( contrast( mix, bgRgb ) >= target ) {
				return toHex( mix );
			}
		}

		return '#000000';
	}

	/* ── Date helpers ──────────────────────────────────────────────────
	 * Event times arrive as naive wall-clock strings in the site's
	 * timezone ("2026-08-05T19:00:00"). They're parsed as-is so every
	 * visitor sees the same posted time, not a time shifted into their
	 * own zone — which is what people expect from a local events listing.
	 */

	function parseLocal( value ) {
		if ( ! value ) {
			return new Date();
		}
		var m = /^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}):(\d{2}))?$/.exec( value );
		if ( ! m ) {
			return new Date( value );
		}
		return new Date(
			parseInt( m[ 1 ], 10 ),
			parseInt( m[ 2 ], 10 ) - 1,
			parseInt( m[ 3 ], 10 ),
			m[ 4 ] ? parseInt( m[ 4 ], 10 ) : 0,
			m[ 5 ] ? parseInt( m[ 5 ], 10 ) : 0,
			m[ 6 ] ? parseInt( m[ 6 ], 10 ) : 0
		);
	}

	function startOfToday() {
		var d = new Date();
		d.setHours( 0, 0, 0, 0 );
		return d;
	}

	function isSameDay( a, b ) {
		return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
	}

	function dayStamp( d ) {
		return new Date( d.getFullYear(), d.getMonth(), d.getDate() ).getTime();
	}

	/**
	 * The last calendar day an event occupies.
	 *
	 * iCal all-day DTEND is exclusive (a one-day event ends the next
	 * midnight), so an all-day end is pulled back a day. A timed event's
	 * last day is the day its end instant falls on. An empty end means the
	 * source gave a start only.
	 */
	function eventLastDay( ev ) {
		var start = parseLocal( ev.start );
		if ( ! ev.end ) {
			return start;
		}
		var end = parseLocal( ev.end );
		if ( ev.isAllDay ) {
			var last = new Date( end.getFullYear(), end.getMonth(), end.getDate() - 1 );
			return last < start ? start : last;
		}
		return end < start ? start : end;
	}

	/** True for every day a (possibly multi-day) event spans. */
	function eventOccursOnDay( ev, day ) {
		var d = dayStamp( day );
		return d >= dayStamp( parseLocal( ev.start ) ) && d <= dayStamp( eventLastDay( ev ) );
	}

	function isMultiDay( ev ) {
		return dayStamp( eventLastDay( ev ) ) > dayStamp( parseLocal( ev.start ) );
	}

	function pad( n ) {
		return n < 10 ? '0' + n : '' + n;
	}

	function isoNaive( d ) {
		return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() ) +
			'T' + pad( d.getHours() ) + ':' + pad( d.getMinutes() ) + ':' + pad( d.getSeconds() );
	}

	function fmt( date, opts, locale ) {
		try {
			return date.toLocaleString( locale || 'en-US', opts );
		} catch ( e ) {
			return date.toLocaleString( 'en-US', opts );
		}
	}

	/** Rolling range anchored on today — used by the card view. */
	function rollingRange( unit, offset ) {
		var start = startOfToday();
		var end;
		if ( 'day' === unit ) {
			start.setDate( start.getDate() + offset );
			end = new Date( start );
		} else if ( 'week' === unit ) {
			start.setDate( start.getDate() + offset * 7 );
			end = new Date( start );
			end.setDate( start.getDate() + 7 );
		} else {
			start.setMonth( start.getMonth() + offset );
			end = new Date( start );
			end.setMonth( start.getMonth() + 1 );
		}
		end.setHours( 23, 59, 59, 999 );
		return { start: start, end: end };
	}

	/** Calendar-aligned range — used by the grid so weeks line up. */
	function alignedRange( unit, offset ) {
		var today = startOfToday();
		if ( 'day' === unit ) {
			var day = new Date( today );
			day.setDate( today.getDate() + offset );
			var dayEnd = new Date( day );
			dayEnd.setHours( 23, 59, 59, 999 );
			return { start: day, end: dayEnd };
		}
		if ( 'week' === unit ) {
			var sunday = new Date( today );
			sunday.setDate( today.getDate() - today.getDay() + offset * 7 );
			var saturday = new Date( sunday );
			saturday.setDate( sunday.getDate() + 6 );
			saturday.setHours( 23, 59, 59, 999 );
			return { start: sunday, end: saturday };
		}
		var first = new Date( today.getFullYear(), today.getMonth() + offset, 1 );
		var last = new Date( first.getFullYear(), first.getMonth() + 1, 0 );
		last.setHours( 23, 59, 59, 999 );
		return { start: first, end: last };
	}

	function rangeLabel( start, end, locale ) {
		if ( isSameDay( start, end ) ) {
			return fmt( start, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }, locale );
		}
		var lastOfMonth = new Date( end.getFullYear(), end.getMonth() + 1, 0 ).getDate();
		if ( 1 === start.getDate() && end.getDate() === lastOfMonth && start.getMonth() === end.getMonth() ) {
			return fmt( start, { month: 'long', year: 'numeric' }, locale );
		}
		return fmt( start, { month: 'short', day: 'numeric' }, locale ) + ' – ' +
			fmt( end, { month: 'short', day: 'numeric', year: 'numeric' }, locale );
	}

	/** "Fri, Sep 25" or, for a multi-day event, "Sep 25 – Sep 27". */
	function eventDateText( ev, locale ) {
		var start = parseLocal( ev.start );
		if ( isMultiDay( ev ) ) {
			var md = { month: 'short', day: 'numeric' };
			return fmt( start, md, locale ) + ' – ' + fmt( eventLastDay( ev ), md, locale );
		}
		return fmt( start, { weekday: 'short', month: 'short', day: 'numeric' }, locale );
	}

	function fmtTime( value, isAllDay, allDayLabel, locale ) {
		if ( isAllDay ) {
			return allDayLabel;
		}
		return fmt( parseLocal( value ), { hour: 'numeric', minute: '2-digit', hour12: true }, locale );
	}

	function fmtDuration( start, end, isAllDay ) {
		if ( isAllDay || ! end ) {
			return '';
		}
		var ms = parseLocal( end ).getTime() - parseLocal( start ).getTime();
		if ( ms <= 0 ) {
			return '';
		}
		var mins = Math.round( ms / 60000 );
		var hrs = Math.floor( mins / 60 );
		var rem = mins % 60;
		if ( 0 === hrs ) {
			return rem + 'm';
		}
		if ( 0 === rem ) {
			return hrs + 'h';
		}
		return hrs + 'h ' + rem + 'm';
	}

	function timeSince( iso ) {
		var mins = Math.floor( ( Date.now() - new Date( iso ).getTime() ) / 60000 );
		if ( mins < 1 ) {
			return 'just now';
		}
		if ( mins < 60 ) {
			return mins + 'm ago';
		}
		var hrs = Math.floor( mins / 60 );
		if ( hrs < 24 ) {
			return hrs + 'h ago';
		}
		return Math.floor( hrs / 24 ) + 'd ago';
	}

	/* ── Text helpers ─────────────────────────────────────────────────── */

	function escapeHtml( value ) {
		return String( value == null ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function unwrapGoogleRedirect( url ) {
		try {
			var u = new URL( url );
			if ( 'www.google.com' === u.hostname && '/url' === u.pathname && u.searchParams.get( 'q' ) ) {
				return u.searchParams.get( 'q' );
			}
		} catch ( e ) { /* not absolute — leave it */ }
		return url;
	}

	/**
	 * Google's iCal export produces messy descriptions: sometimes clean
	 * markdown links, sometimes a markdown link wrapping a raw anchor whose
	 * href is a google.com/url redirect. Normalise all of it to plain <a>
	 * tags, linkify bare URLs, and strip everything else.
	 */
	function linkify( raw ) {
		if ( ! raw ) {
			return '';
		}
		var stash = [];
		function keep( html ) {
			stash.push( html );
			return '\u0000' + ( stash.length - 1 ) + '\u0000';
		}
		function anchor( label, href ) {
			var clean = unwrapGoogleRedirect( href );
			if ( ! /^https?:\/\//i.test( clean ) ) {
				return escapeHtml( label );
			}
			return '<a href="' + escapeHtml( clean ) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml( label ) + '</a>';
		}

		var text = String( raw );
		text = text.replace( /\[([^\]]+)\]\(\s*<a\s+href="([^"]+)"[^>]*>[^<]*<\/a>\s*\)/gi, function ( _m, label, href ) {
			return keep( anchor( label, href ) );
		} );
		text = text.replace( /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/gi, function ( _m, label, href ) {
			return keep( anchor( label, href ) );
		} );
		text = text.replace( /<a\s+href="([^"]+)"[^>]*>([^<]*)<\/a>/gi, function ( _m, href, label ) {
			return keep( anchor( label || href, href ) );
		} );
		text = text.replace( /<[^>]*>/g, ' ' );
		text = text.replace( /(https?:\/\/[^\s<)]+)/gi, function ( url ) {
			return keep( anchor( url, url ) );
		} );

		return text.split( /\u0000(\d+)\u0000/g ).map( function ( part, i ) {
			return i % 2 ? stash[ Number( part ) ] : escapeHtml( part );
		} ).join( '' ).trim();
	}

	function plainText( raw ) {
		return String( raw || '' ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
	}

	/** Add-to-calendar link. ctz keeps the times in the calendar's own zone. */
	function addToCalendarUrl( ev, timezone ) {
		var dates;
		if ( ev.isAllDay ) {
			var startDate = ev.start.slice( 0, 10 ).replace( /-/g, '' );
			var endSource = ev.end ? ev.end.slice( 0, 10 ) : ev.start.slice( 0, 10 );
			var endObj = new Date( endSource + 'T00:00:00' );
			// Google treats the all-day end as exclusive.
			if ( ! ev.end || endSource === ev.start.slice( 0, 10 ) ) {
				endObj.setDate( endObj.getDate() + 1 );
			}
			dates = startDate + '/' + endObj.getFullYear() + pad( endObj.getMonth() + 1 ) + pad( endObj.getDate() );
		} else {
			var s = parseLocal( ev.start );
			var e = ev.end ? parseLocal( ev.end ) : new Date( s.getTime() + 3600000 );
			dates = isoNaive( s ).replace( /[-:]/g, '' ) + '/' + isoNaive( e ).replace( /[-:]/g, '' );
		}

		var params = [
			'action=TEMPLATE',
			'text=' + encodeURIComponent( ev.title || '' ),
			'dates=' + dates,
			'details=' + encodeURIComponent( plainText( ev.description ).slice( 0, 900 ) ),
			'location=' + encodeURIComponent( ev.location || '' )
		];
		if ( timezone ) {
			params.push( 'ctz=' + encodeURIComponent( timezone ) );
		}
		return 'https://calendar.google.com/calendar/render?' + params.join( '&' );
	}

	/**
	 * Inline SVG for the UI's own controls.
	 *
	 * These used to be text glyphs (‹ › ⟳ − + ☾ ☀ 🔍). Their contrast was
	 * fine when measured by hand — 5.24 light, 6.2 dark — but a glyph is a
	 * character, not a shape, so contrast checkers can only report it as
	 * "unable to determine" and it stays on the report forever. An SVG has
	 * no text node at all, so the rule no longer applies. It also renders
	 * identically everywhere, which glyphs and emoji very much do not.
	 *
	 * aria-hidden because every one of these lives in a button that already
	 * carries an aria-label.
	 *
	 * @param {string} name Icon name.
	 * @return {string} SVG markup.
	 */
	function svgIcon( name ) {
		var paths = {
			prev: '<path d="M15 18l-6-6 6-6"/>',
			next: '<path d="M9 18l6-6-6-6"/>',
			refresh: '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/>',
			minus: '<path d="M5 12h14"/>',
			plus: '<path d="M12 5v14"/><path d="M5 12h14"/>',
			sun: '<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.5 1.5M17.6 17.6l1.5 1.5M19.1 4.9l-1.5 1.5M6.4 17.6l-1.5 1.5"/>',
			moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
			info: '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
			search: '<circle cx="11" cy="11" r="7"/><path d="M20.5 20.5l-4.2-4.2"/>'
		};

		if ( ! paths[ name ] ) {
			return '';
		}

		return '<svg class="wpcc-svg" viewBox="0 0 24 24" width="1em" height="1em" ' +
			'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
			'stroke-linejoin="round" aria-hidden="true" focusable="false">' +
			paths[ name ] + '</svg>';
	}

	function iconHtml( icon, size ) {
		if ( ! icon ) {
			return '';
		}
		if ( /^(https?:\/\/|\/|data:image\/)/.test( String( icon ).trim() ) ) {
			return '<img class="wpcc-icon-img" src="' + escapeHtml( icon ) + '" alt="" aria-hidden="true" style="width:' + size + 'px;height:' + size + 'px" />';
		}
		// aria-hidden: the category label always sits right next to the icon,
		// so announcing the emoji only adds noise ("classical building, Ward
		// Office"). It also keeps contrast checkers off a glyph they can't
		// measure.
		return '<span class="wpcc-icon" aria-hidden="true" style="font-size:' + Math.round( size * 0.9 ) + 'px">' + escapeHtml( icon ) + '</span>';
	}

	/* ── Cookies ───────────────────────────────────────────────────────
	 * Used only for the opt-in "Remember my filter" checkbox. First-party,
	 * browser-local, never read by the server.
	 */
	var REMEMBER_COOKIE = 'cc_remember_filter';
	var FILTER_COOKIE = 'cc_category_filter';

	function getCookie( name ) {
		var m = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		if ( ! m ) {
			return null;
		}
		try {
			return decodeURIComponent( m[ 1 ] );
		} catch ( e ) {
			return null;
		}
	}

	function setCookie( name, value ) {
		var secure = 'https:' === window.location.protocol ? '; Secure' : '';
		document.cookie = name + '=' + encodeURIComponent( value ) +
			'; Max-Age=' + ( 365 * 86400 ) + '; Path=/; SameSite=Lax' + secure;
	}

	function deleteCookie( name ) {
		document.cookie = name + '=; Max-Age=0; Path=/; SameSite=Lax';
	}

	function el( tag, className, html ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== html && null !== html ) {
			node.innerHTML = html;
		}
		return node;
	}

	/* ── The calendar instance ─────────────────────────────────────────── */

	function Calendar( root ) {
		var config;
		try {
			config = JSON.parse( root.getAttribute( 'data-wpcc-config' ) || '{}' );
		} catch ( e ) {
			config = {};
		}

		var strings = config.strings || {};
		var locale = config.locale || 'en-US';

		var state = {
			view: config.view || 'cards',
			unit: config.unit || 'month',
			offset: 0,
			theme: config.theme || 'light',
			zoom: ZOOM_DEFAULT,
			search: '',
			selected: [],
			remember: false,
			aboutOpen: false,
			loading: true,
			error: null,
			data: null,
			fetchedAt: null
		};

		// Opt-in: the filter is only read from / written to a cookie once the
		// visitor has ticked "Remember my filter".
		if ( config.showRemember && '1' === getCookie( REMEMBER_COOKIE ) ) {
			state.remember = true;
			var saved = getCookie( FILTER_COOKIE );
			if ( saved ) {
				state.selected = saved.split( ',' ).filter( Boolean );
			}
		}

		function setSelected( next ) {
			state.selected = next;
			if ( state.remember ) {
				writeFilterCookie();
			}
		}

		function writeFilterCookie() {
			if ( ! state.selected.length ) {
				deleteCookie( FILTER_COOKIE );
			} else {
				setCookie( FILTER_COOKIE, state.selected.join( ',' ) );
			}
		}

		function setRemember( on ) {
			state.remember = on;
			if ( on ) {
				setCookie( REMEMBER_COOKIE, '1' );
				writeFilterCookie();
			} else {
				deleteCookie( REMEMBER_COOKIE );
				deleteCookie( FILTER_COOKIE );
				// The current selection stays for this visit; it just isn't kept.
			}
		}

		var openPopup = null;
		var openAbout = null;
		var aboutReturnFocus = null;
		var hoverTimer = null;
		var hideTimer = null;
		var requestId = 0;

		root.style.setProperty( '--wpcc-accent', config.accent || '#147671' );
		root.style.setProperty( '--wpcc-highlight', config.highlight || '#CF2C28' );

		/**
		 * Apply a category colour to a node.
		 *
		 * --wpcc-cat keeps the raw value for borders and backgrounds.
		 * --wpcc-cat-text carries a contrast-corrected variant for the places
		 * the colour is painted as text, which is the only part WCAG 1.4.3
		 * governs. Recomputed on every render, so the theme toggle picks up
		 * the new surface.
		 *
		 * @param {Element} node  Node to set the variables on.
		 * @param {string}  color Category colour.
		 */
		function setCat( node, color ) {
			var value = color || config.accent || '#147671';
			node.style.setProperty( '--wpcc-cat', value );
			node.style.setProperty(
				'--wpcc-cat-text',
				readableOn( value, SURFACE[ state.theme ] || SURFACE.light, 4.5 )
			);
			// For the places the category colour is the *background* (the count
			// badge), where a hardcoded white sat as low as 3.54 on the greens
			// and greys.
			node.style.setProperty( '--wpcc-cat-fg', inkOn( value, 4.5 ) );
		}

		function isTouch() {
			return window.matchMedia && window.matchMedia( '(hover: none)' ).matches;
		}

		function currentRange() {
			return 'cards' === state.view ? rollingRange( state.unit, state.offset ) : alignedRange( state.unit, state.offset );
		}

		/* ── Data ──────────────────────────────────────────────────────── */

		function load() {
			var range = currentRange();
			var url = config.restUrl +
				( config.restUrl.indexOf( '?' ) === -1 ? '?' : '&' ) +
				'timeMin=' + encodeURIComponent( isoNaive( range.start ) ) +
				'&timeMax=' + encodeURIComponent( isoNaive( range.end ) );

			var id = ++requestId;
			state.loading = true;
			state.error = null;
			render();

			fetch( url, { credentials: 'same-origin' } )
				.then( function ( res ) {
					if ( ! res.ok ) {
						throw new Error( 'HTTP ' + res.status );
					}
					return res.json();
				} )
				.then( function ( payload ) {
					if ( id !== requestId ) {
						return; // A newer request already won.
					}
					state.data = payload;
					state.fetchedAt = new Date().toISOString();
					state.loading = false;
					render();
				} )
				.catch( function ( err ) {
					if ( id !== requestId ) {
						return;
					}
					state.loading = false;
					state.error = err.message || String( err );
					render();
				} );
		}

		/** Categories that survive the shortcode's `categories` attribute. */
		function allowedCategory( key ) {
			if ( ! config.onlyCategories || ! config.onlyCategories.length ) {
				return true;
			}
			return config.onlyCategories.indexOf( key ) !== -1;
		}

		function visibleEvents() {
			if ( ! state.data || ! state.data.events ) {
				return [];
			}
			return state.data.events.filter( function ( ev ) {
				return allowedCategory( ev.category );
			} );
		}

		/** "Also listed by A, B" for events merged from several sources. */
		function alsoListedBy( ev ) {
			var others = ( ev.sources || [] ).filter( function ( name ) {
				return name && name !== ev.organizer;
			} );
			if ( ! others.length ) {
				return '';
			}
			return ( strings.alsoListedBy || 'Also listed by' ) + ' ' + others.join( ', ' );
		}

		/* ── Popup ─────────────────────────────────────────────────────── */

		function closePopup() {
			if ( openPopup && openPopup.parentNode ) {
				openPopup.parentNode.removeChild( openPopup );
			}
			openPopup = null;
		}

		function showPopup( ev, anchorEl ) {
			closePopup();

			var color = ev.categoryColor;
			var timeStr = ev.isAllDay
				? ( strings.allDay || 'All day' )
				: fmtTime( ev.start, false, strings.allDay, locale ) + ( ev.end ? ' – ' + fmtTime( ev.end, false, strings.allDay, locale ) : '' );
			var dur = fmtDuration( ev.start, ev.end, ev.isAllDay );

			// The popup lives on <body> (so no theme wrapper can clip it), which
			// means it has to carry the theme class and accent vars itself.
			var popup = el( 'div', 'wpcc-popup wpcc-theme-' + state.theme );
			setCat( popup, color );
			popup.style.setProperty( '--wpcc-accent', config.accent || '#147671' );
			popup.style.setProperty( '--wpcc-highlight', config.highlight || '#CF2C28' );

			var html = '<div class="wpcc-popup-bar"></div>' +
				'<button type="button" class="wpcc-popup-close" aria-label="' + escapeHtml( strings.close || 'Close' ) + '">✕</button>' +
				'<div class="wpcc-popup-head">' +
					'<div class="wpcc-popup-cat">' + iconHtml( ev.categoryIcon, 12 ) + ' ' + escapeHtml( ev.categoryLabel ) + '</div>' +
					'<div class="wpcc-popup-title">' + escapeHtml( ev.title ) + '</div>' +
				'</div>' +
				'<div class="wpcc-popup-body">' +
					'<div class="wpcc-popup-row">' +
						'<span class="wpcc-popup-ico">🕒</span>' +
						'<div><div class="wpcc-popup-date">' +
							escapeHtml( eventDateText( ev, locale ) ) +
						'</div><div class="wpcc-popup-time">' + escapeHtml( timeStr ) +
						( dur ? ' <span class="wpcc-chip-dur">' + escapeHtml( dur ) + '</span>' : '' ) +
						'</div></div>' +
					'</div>';

			if ( ev.location ) {
				html += '<div class="wpcc-popup-row"><span class="wpcc-popup-ico">📍</span>' +
					'<div class="wpcc-popup-muted"><a href="https://www.google.com/maps/search/?api=1&query=' +
					encodeURIComponent( ev.location ) + '" target="_blank" rel="noopener noreferrer">' +
					escapeHtml( ev.location ) + '</a></div></div>';
			}
			if ( ev.description ) {
				html += '<div class="wpcc-popup-row"><span class="wpcc-popup-ico">📝</span>' +
					'<div class="wpcc-popup-desc">' + linkify( ev.description ) + '</div></div>';
			}
			if ( ev.organizer ) {
				html += '<div class="wpcc-popup-row"><span class="wpcc-popup-ico">👤</span>' +
					'<div class="wpcc-popup-muted">' + escapeHtml( ev.organizer ) + '</div></div>';
			}
			var alsoPopup = alsoListedBy( ev );
			if ( alsoPopup ) {
				html += '<div class="wpcc-popup-row"><span class="wpcc-popup-ico">🔗</span>' +
					'<div class="wpcc-popup-muted">' + escapeHtml( alsoPopup ) + '</div></div>';
			}

			html += '<div class="wpcc-popup-sep"></div>';
			if ( ev.htmlLink ) {
				html += '<a class="wpcc-popup-link" href="' + escapeHtml( ev.htmlLink ) + '" target="_blank" rel="noopener noreferrer">↗ ' +
					escapeHtml( strings.openSource || 'Open source event' ) + '</a>';
			}
			html += '<a class="wpcc-popup-link" href="' + escapeHtml( addToCalendarUrl( ev, config.timezone ) ) +
				'" target="_blank" rel="noopener noreferrer">📅 ' + escapeHtml( strings.addToCalendar || 'Add to my calendar' ) + '</a>';
			html += '</div>';

			popup.innerHTML = html;

			popup.querySelector( '.wpcc-popup-close' ).addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				closePopup();
			} );
			popup.addEventListener( 'mouseenter', function () {
				clearTimeout( hideTimer );
			} );
			popup.addEventListener( 'mouseleave', function () {
				if ( ! isTouch() ) {
					hideTimer = setTimeout( closePopup, 250 );
				}
			} );

			document.body.appendChild( popup );
			openPopup = popup;
			position( popup, anchorEl );
		}

		function position( popup, anchorEl ) {
			var a = anchorEl.getBoundingClientRect();
			var p = popup.getBoundingClientRect();
			var vw = window.innerWidth;
			var vh = window.innerHeight;
			var gap = 12;
			var top;
			var left;

			if ( vw < 640 ) {
				left = Math.max( 8, Math.min( ( vw - p.width ) / 2, vw - p.width - 8 ) );
				top = a.bottom + gap;
				if ( top + p.height > vh - 8 ) {
					top = a.top - p.height - gap;
				}
			} else {
				left = a.right + gap;
				if ( left + p.width > vw - 8 ) {
					left = a.left - p.width - gap;
				}
				if ( left < 8 ) {
					left = 8;
				}
				top = a.top + a.height / 2 - p.height / 2;
			}

			top = Math.max( 8, Math.min( top, vh - p.height - 8 ) );
			popup.style.top = ( top + window.scrollY ) + 'px';
			popup.style.left = ( left + window.scrollX ) + 'px';
		}

		/** Hover on pointer devices, tap on touch — same popup either way. */
		function bindPopup( node, ev ) {
			node.addEventListener( 'mouseenter', function () {
				if ( isTouch() ) {
					return;
				}
				clearTimeout( hideTimer );
				clearTimeout( hoverTimer );
				hoverTimer = setTimeout( function () {
					showPopup( ev, node );
				}, 280 );
			} );
			node.addEventListener( 'mouseleave', function () {
				clearTimeout( hoverTimer );
				if ( ! isTouch() ) {
					hideTimer = setTimeout( closePopup, 250 );
				}
			} );
			node.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( 'a' ) ) {
					return; // Let real links through.
				}
				e.preventDefault();
				if ( openPopup ) {
					closePopup();
					return;
				}
				showPopup( ev, node );
			} );
		}


		/* ── About modal ───────────────────────────────────────────────── */

		function focusables( container ) {
			return Array.prototype.slice.call( container.querySelectorAll( 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])' ) );
		}

		function onAboutKey( e ) {
			if ( ! openAbout ) {
				return;
			}
			if ( 'Escape' === e.key ) {
				e.preventDefault();
				closeAbout();
				return;
			}
			if ( 'Tab' === e.key ) {
				var items = focusables( openAbout );
				if ( ! items.length ) {
					return;
				}
				var first = items[ 0 ];
				var last = items[ items.length - 1 ];
				if ( e.shiftKey && ( document.activeElement === first || document.activeElement === openAbout.firstChild ) ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				} else if ( ! openAbout.contains( document.activeElement ) ) {
					e.preventDefault();
					first.focus();
				}
			}
		}

		function closeAbout() {
			if ( ! openAbout ) {
				return;
			}
			document.removeEventListener( 'keydown', onAboutKey, true );
			if ( openAbout.parentNode ) {
				openAbout.parentNode.removeChild( openAbout );
			}
			openAbout = null;
			document.documentElement.classList.remove( 'wpcc-no-scroll' );
			// The toolbar is rebuilt on every render, so look the button up again.
			var opener = root.querySelector( '.wpcc-about-btn' );
			if ( opener ) {
				opener.focus();
			}
		}

		function aboutBody() {
			var html = '<p class="wpcc-about-intro">' + escapeHtml( strings.aboutIntro || '' ) + '</p>' +
				'<h3 class="wpcc-about-h">' + escapeHtml( strings.aboutSources || 'Where the events come from' ) + '</h3>';

			var sources = state.data && state.data.sources;
			if ( state.loading && ! sources ) {
				html += '<p class="wpcc-about-note">' + escapeHtml( strings.aboutLoading || 'Loading sources…' ) + '</p>';
			} else if ( ! sources || ! sources.length ) {
				html += '<p class="wpcc-about-note">' + escapeHtml( strings.aboutNoSources || 'The source list is not available right now.' ) + '</p>';
			} else {
				html += '<ul class="wpcc-about-sources">';
				sources.forEach( function ( src ) {
					html += '<li><span class="wpcc-about-name">' + escapeHtml( src.name ) + '</span>';
					if ( src.link && /^https?:\/\//i.test( src.link ) ) {
						html += '<a href="' + escapeHtml( src.link ) + '" target="_blank" rel="noopener noreferrer">' +
							escapeHtml( strings.visitSite || 'Visit site' ) + ' ↗</a>';
					}
					html += '</li>';
				} );
				html += '</ul>';
			}

			html += '<p class="wpcc-about-note">' + escapeHtml( strings.aboutCorrections || '' ) + '</p>';

			if ( config.contactEmail ) {
				html += '<p class="wpcc-about-note">' + escapeHtml( strings.aboutContact || '' ) + ' ' +
					'<a href="mailto:' + escapeHtml( config.contactEmail ) + '">✉ ' + escapeHtml( config.contactEmail ) + '</a></p>';
			}

			html += '<h3 class="wpcc-about-h">' + escapeHtml( strings.aboutPrivacy || 'Privacy' ) + '</h3>' +
				'<p class="wpcc-about-note">' + escapeHtml( strings.aboutPrivacyBody || '' ) +
				( config.showRemember ? ' ' + escapeHtml( strings.aboutPrivacyRemember || '' ) : '' ) + '</p>';
			return html;
		}

		function showAbout() {
			closePopup();
			closeAbout();

			// Lives on <body> like the event popup, so it carries the theme
			// class and accent vars itself.
			var overlay = el( 'div', 'wpcc-about-overlay wpcc-theme-' + state.theme );
			overlay.style.setProperty( '--wpcc-accent', config.accent || '#147671' );
			overlay.style.setProperty( '--wpcc-highlight', config.highlight || '#CF2C28' );

			var backdrop = el( 'button', 'wpcc-about-backdrop' );
			backdrop.type = 'button';
			backdrop.tabIndex = -1;
			backdrop.setAttribute( 'aria-label', strings.close || 'Close' );
			backdrop.addEventListener( 'click', closeAbout );
			overlay.appendChild( backdrop );

			var dialog = el( 'div', 'wpcc-about' );
			dialog.setAttribute( 'role', 'dialog' );
			dialog.setAttribute( 'aria-modal', 'true' );
			dialog.setAttribute( 'aria-labelledby', 'wpcc-about-title' );
			dialog.tabIndex = -1;
			dialog.innerHTML = '<div class="wpcc-about-bar"></div>' +
				'<div class="wpcc-about-inner">' +
					'<div class="wpcc-about-head">' +
						'<h2 id="wpcc-about-title">' + escapeHtml( strings.about || 'About this calendar' ) + '</h2>' +
						'<button type="button" class="wpcc-about-close" aria-label="' + escapeHtml( strings.close || 'Close' ) + '">✕</button>' +
					'</div>' + aboutBody() +
				'</div>';
			dialog.querySelector( '.wpcc-about-close' ).addEventListener( 'click', closeAbout );
			overlay.appendChild( dialog );

			document.body.appendChild( overlay );
			document.documentElement.classList.add( 'wpcc-no-scroll' );
			openAbout = overlay;
			document.addEventListener( 'keydown', onAboutKey, true );
			dialog.focus();
		}

		/* ── Rendering ─────────────────────────────────────────────────── */

		function render() {
			var scrollY = window.scrollY;
			closePopup();
			root.className = 'wpcc-app wpcc-theme-' + state.theme;
			root.innerHTML = '';

			if ( config.showControls ) {
				root.appendChild( renderControls() );
			}
			if ( state.error ) {
				root.appendChild( renderError() );
			}
			if ( state.data && state.data.errors && state.data.errors.length ) {
				root.appendChild( renderFeedWarnings( state.data.errors ) );
			}
			if ( config.showSearch ) {
				root.appendChild( renderSearch() );
			}

			var body = el( 'div', 'wpcc-body' );
			root.appendChild( body );
			// render() rebuilds the DOM, so the current zoom has to be
			// re-applied to the fresh body node or it resets on every reload.
			applyZoom();

			if ( state.loading ) {
				body.appendChild( el( 'div', 'wpcc-loading', '<span class="wpcc-spinner" aria-hidden="true"></span>' ) );
			} else if ( state.search.trim() ) {
				body.appendChild( renderSearchResults() );
			} else if ( 'cards' === state.view ) {
				body.appendChild( renderCards() );
			} else {
				body.appendChild( renderGrid() );
			}

			window.scrollTo( window.scrollX, scrollY );
			sizeCardLists();
		}

		/**
		 * Size every card body to exactly MAX_VISIBLE_ROWS rows.
		 *
		 * Row heights vary (a long title wraps, a location line may be absent,
		 * the "Earlier this period" divider adds its own height), so the fixed
		 * max-height in the stylesheet is only a pre-layout fallback. Here we
		 * measure the first MAX_VISIBLE_ROWS children and cap the list there, so
		 * a card with four events shows all four and a card with five or more
		 * scrolls. Cards in the same grid row then stay the same height.
		 */
		function sizeCardLists() {
			var lists = root.querySelectorAll( '.wpcc-card-list' );

			for ( var i = 0; i < lists.length; i++ ) {
				var list = lists[ i ];
				var kids = list.children;
				list.style.maxHeight = '';

				// Walk the children until MAX_VISIBLE_ROWS event rows are covered.
				// Non-row children (the "Earlier this period" divider) count
				// towards the height but not the row budget.
				var seen = 0;
				var covered = 0;
				var last = null;

				for ( var j = 0; j < kids.length; j++ ) {
					var kid = kids[ j ];
					var isRow = kid.classList.contains( 'wpcc-row' );

					if ( isRow && seen >= MAX_VISIBLE_ROWS ) {
						break;
					}

					last = kid;
					covered = j + 1;

					if ( isRow ) {
						seen++;
					}
				}

				if ( ! last || covered >= kids.length ) {
					list.classList.remove( 'is-scrollable' );
					setScrollable( list, false );
					continue;
				}

				// Measure from the list's top edge to the last visible row's
				// bottom edge rather than summing offsetHeight, so row margins
				// are included. Rects are viewport-relative, so unscroll first.
				var prevScroll = list.scrollTop;
				list.scrollTop = 0;
				var h = last.getBoundingClientRect().bottom -
					list.getBoundingClientRect().top +
					parseFloat( window.getComputedStyle( list ).paddingBottom );
				list.scrollTop = prevScroll;

				list.classList.add( 'is-scrollable' );
				setScrollable( list, true );
				list.style.maxHeight = Math.ceil( h ) + 'px';
			}
		}

		/**
		 * Make a capped card list reachable by keyboard.
		 *
		 * A div that scrolls but can't be focused is unreachable without a
		 * mouse — the events past the fourth simply don't exist for a keyboard
		 * user (WCAG 2.1.1). Only lists that actually overflow get a tab stop,
		 * so we don't litter the tab order with stops that do nothing.
		 *
		 * @param {Element} list       The card list.
		 * @param {boolean} scrollable Whether it currently overflows.
		 */
		function setScrollable( list, scrollable ) {
			if ( ! scrollable ) {
				list.removeAttribute( 'tabindex' );
				list.removeAttribute( 'role' );
				list.removeAttribute( 'aria-label' );
				return;
			}

			list.setAttribute( 'tabindex', '0' );
			// Must be application, main or region: those are the only roles
			// accepted for a focusable scroll container. "group" reads fine
			// but fails axe's focus-order-semantics rule, which the scanners
			// enable even though axe ships it disabled by default.
			list.setAttribute( 'role', 'region' );

			var label = list.getAttribute( 'data-label' ) || '';
			list.setAttribute(
				'aria-label',
				label ?
					label + ' — ' + ( strings.scrollableList || 'scrollable event list' ) :
					( strings.scrollableList || 'scrollable event list' )
			);
		}

		function button( label, title, className, onClick ) {
			var b = el( 'button', 'wpcc-btn' + ( className ? ' ' + className : '' ), label );
			b.type = 'button';
			if ( title ) {
				b.title = title;
				b.setAttribute( 'aria-label', title );
			}
			b.addEventListener( 'click', onClick );
			return b;
		}

		/**
		 * Content zoom control.
		 *
		 * The hosted calendar floats a vertical slider against the viewport
		 * edge. That can't work inside a WordPress page — a fixed-position
		 * control would sit on top of the theme's own content and follow the
		 * visitor around the whole page, not just the calendar. So the same
		 * control lives inline in the toolbar here.
		 */
		function renderZoom() {
			var group = el( 'div', 'wpcc-group wpcc-zoom' );
			group.setAttribute( 'role', 'group' );
			group.setAttribute( 'aria-label', strings.zoom || 'Content zoom' );

			var label = el( 'button', 'wpcc-btn wpcc-zoom-value', state.zoom + '%' );
			label.type = 'button';

			var slider = document.createElement( 'input' );
			slider.type = 'range';
			slider.className = 'wpcc-zoom-slider';
			slider.min = ZOOM_MIN;
			slider.max = ZOOM_MAX;
			slider.step = ZOOM_STEP;
			slider.value = state.zoom;
			slider.setAttribute( 'aria-label', strings.zoomLevel || 'Content text zoom level' );

			function apply( value, syncSlider ) {
				state.zoom = Math.max( ZOOM_MIN, Math.min( ZOOM_MAX, value ) );
				if ( syncSlider ) {
					slider.value = state.zoom;
				}
				slider.setAttribute( 'aria-valuetext', state.zoom + '%' );
				label.textContent = state.zoom + '%';
				label.setAttribute(
					'aria-label',
					( strings.zoomReset || 'Reset zoom to 100%' ) + ' (' + state.zoom + '%)'
				);
				applyZoom();
				// Row heights change with zoom, so the four-row cap has to be
				// re-measured or cards keep their old height.
				sizeCardLists();
			}

			var out = button( svgIcon( 'minus' ), strings.zoomOut || 'Decrease text size', 'wpcc-icon-btn', function () {
				apply( state.zoom - ZOOM_STEP, true );
			} );
			var into = button( svgIcon( 'plus' ), strings.zoomIn || 'Increase text size', 'wpcc-icon-btn', function () {
				apply( state.zoom + ZOOM_STEP, true );
			} );

			slider.addEventListener( 'input', function () {
				apply( parseInt( slider.value, 10 ) || ZOOM_DEFAULT, false );
			} );
			label.addEventListener( 'click', function () {
				apply( ZOOM_DEFAULT, true );
			} );

			group.appendChild( out );
			group.appendChild( slider );
			group.appendChild( into );
			group.appendChild( label );

			apply( state.zoom, true );
			return group;
		}

		/** Push the current zoom onto the results body only. */
		function applyZoom() {
			var body = root.querySelector( '.wpcc-body' );
			if ( ! body ) {
				return;
			}
			body.style.zoom = ZOOM_DEFAULT === state.zoom ? '' : state.zoom + '%';
		}

		function renderControls() {
			var range = currentRange();
			var wrap = el( 'div', 'wpcc-controls' );

			var meta = el( 'div', 'wpcc-meta' );
			meta.appendChild( el( 'div', 'wpcc-range', escapeHtml( rangeLabel( range.start, range.end, locale ) ) ) );
			if ( state.fetchedAt ) {
				meta.appendChild( el( 'div', 'wpcc-updated', escapeHtml( ( strings.updated || 'Updated' ) + ' ' + timeSince( state.fetchedAt ) ) ) );
			}
			wrap.appendChild( meta );

			var bar = el( 'div', 'wpcc-bar' );

			// Today / week / month.
			var unitGroup = el( 'div', 'wpcc-group' );
			[ [ 'day', strings.day || 'Today' ], [ 'week', strings.week || 'Week' ], [ 'month', strings.month || 'Month' ] ].forEach( function ( pair ) {
				var b = button( escapeHtml( pair[ 1 ] ), null, state.unit === pair[ 0 ] ? 'is-active' : '', function () {
					state.unit = pair[ 0 ];
					state.offset = 0;
					load();
				} );
				unitGroup.appendChild( b );
			} );
			bar.appendChild( unitGroup );

			// Navigation.
			var nav = el( 'div', 'wpcc-group' );
			var isDay = 'day' === state.unit;
			nav.appendChild( button( svgIcon( 'prev' ), isDay ? ( strings.prevDay || 'Previous day' ) : ( strings.prev || 'Previous' ), 'wpcc-icon-btn', function () {
				state.offset -= 1;
				load();
			} ) );
			nav.appendChild( button( escapeHtml( strings.today || 'Today' ), null, '', function () {
				state.offset = 0;
				load();
			} ) );
			nav.appendChild( button( svgIcon( 'next' ), isDay ? ( strings.nextDay || 'Next day' ) : ( strings.next || 'Next' ), 'wpcc-icon-btn', function () {
				state.offset += 1;
				load();
			} ) );
			bar.appendChild( nav );

			// View switch.
			var viewGroup = el( 'div', 'wpcc-group' );
			[ [ 'cards', strings.cards || 'Cards' ], [ 'calendar', strings.calendar || 'Calendar' ] ].forEach( function ( pair ) {
				viewGroup.appendChild( button( escapeHtml( pair[ 1 ] ), null, state.view === pair[ 0 ] ? 'is-active' : '', function () {
					if ( state.view === pair[ 0 ] ) {
						return;
					}
					state.view = pair[ 0 ];
					load(); // The two views use different range shapes.
				} ) );
			} );
			bar.appendChild( viewGroup );

			bar.appendChild( button( svgIcon( 'refresh' ), strings.refresh || 'Refresh', 'wpcc-icon-btn', function () {
				load();
			} ) );

			if ( config.showAbout ) {
				bar.appendChild( button( svgIcon( 'info' ), strings.about || 'About this calendar', 'wpcc-icon-btn wpcc-about-btn', function () {
					showAbout();
				} ) );
			}

			if ( config.showZoom ) {
				bar.appendChild( renderZoom() );
			}

			if ( config.showThemeToggle ) {
				bar.appendChild( button(
					svgIcon( 'dark' === state.theme ? 'sun' : 'moon' ),
					'dark' === state.theme ? ( strings.lightMode || 'Light mode' ) : ( strings.darkMode || 'Dark mode' ),
					'wpcc-icon-btn',
					function () {
						state.theme = 'dark' === state.theme ? 'light' : 'dark';
						render();
					}
				) );
			}

			if ( config.submitUrl ) {
				var link = el( 'a', 'wpcc-submit', escapeHtml( config.submitLabel || 'Submit Your Event' ) + ' ↗' );
				link.href = config.submitUrl;
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
				bar.appendChild( link );
			}

			wrap.appendChild( bar );
			return wrap;
		}

		function renderError() {
			var box = el( 'div', 'wpcc-error' );
			box.innerHTML = '<strong>' + escapeHtml( strings.loadFailed || 'Failed to load events' ) + '</strong>' +
				'<span>' + escapeHtml( state.error ) + '</span>';
			box.appendChild( button( escapeHtml( strings.retry || 'Retry' ), null, 'wpcc-retry', function () {
				load();
			} ) );
			return box;
		}

		function renderFeedWarnings( errors ) {
			return el( 'div', 'wpcc-warning-box', '<strong>' + escapeHtml( strings.loadFailed || 'Some feeds failed' ) +
				':</strong> ' + escapeHtml( errors.join( ' · ' ) ) );
		}

		function renderSearch() {
			var wrap = el( 'div', 'wpcc-search' + ( state.search ? ' is-active' : '' ) );
			wrap.innerHTML = '<span class="wpcc-search-ico" aria-hidden="true">' + svgIcon( 'search' ) + '</span>';

			var input = el( 'input' );
			input.type = 'search';
			input.value = state.search;
			input.placeholder = strings.searchPlaceholder || 'Search events in this view…';
			input.setAttribute( 'aria-label', input.placeholder );

			var debounce = null;
			input.addEventListener( 'input', function () {
				var value = input.value;
				clearTimeout( debounce );
				debounce = setTimeout( function () {
					state.search = value;
					var pos = input.selectionStart;
					render();
					// Keep typing uninterrupted across the re-render.
					var next = root.querySelector( '.wpcc-search input' );
					if ( next ) {
						next.focus();
						try {
							next.setSelectionRange( pos, pos );
						} catch ( e ) { /* type=search may refuse */ }
					}
				}, 180 );
			} );
			wrap.appendChild( input );

			if ( state.search ) {
				wrap.appendChild( button( '✕', strings.clearSearch || 'Clear search', 'wpcc-search-clear', function () {
					state.search = '';
					render();
				} ) );
			}
			return wrap;
		}

		/** Category metadata present in the current result set, in configured order. */
		function activeCategories( events ) {
			var seen = {};
			events.forEach( function ( ev ) {
				if ( ! seen[ ev.category ] ) {
					seen[ ev.category ] = {
						key: ev.category,
						label: ev.categoryLabel,
						icon: ev.categoryIcon,
						color: ev.categoryColor
					};
				}
			} );

			var ordered = [];
			( ( state.data && state.data.categories ) || [] ).forEach( function ( cat ) {
				if ( seen[ cat.key ] ) {
					ordered.push( seen[ cat.key ] );
					delete seen[ cat.key ];
				}
			} );
			Object.keys( seen ).forEach( function ( key ) {
				ordered.push( seen[ key ] );
			} );
			return ordered;
		}

		function renderFilters( cats ) {
			var wrap = el( 'div', 'wpcc-filters' );
			if ( ! cats.length ) {
				return wrap;
			}

			var tipRow = el( 'div', 'wpcc-filter-row' );
			tipRow.appendChild( el( 'div', 'wpcc-filter-tip', escapeHtml( strings.filterTip || '' ) ) );

			if ( config.showRemember ) {
				var remember = el( 'label', 'wpcc-remember' + ( state.remember ? ' is-on' : '' ) );
				remember.title = strings.rememberTitle || '';
				var box = document.createElement( 'input' );
				box.type = 'checkbox';
				box.checked = state.remember;
				box.addEventListener( 'change', function () {
					setRemember( box.checked );
					remember.classList.toggle( 'is-on', box.checked );
				} );
				remember.appendChild( box );
				remember.appendChild( document.createTextNode( ' ' + ( strings.rememberFilter || 'Remember my filter' ) ) );
				tipRow.appendChild( remember );
			}
			wrap.appendChild( tipRow );

			var pills = el( 'div', 'wpcc-pills' );
			var allActive = 0 === state.selected.length;

			var all = el( 'button', 'wpcc-pill' + ( allActive ? ' is-active' : '' ), escapeHtml( strings.all || 'All' ) );
			all.type = 'button';
			all.addEventListener( 'click', function () {
				setSelected( [] );
				render();
			} );
			pills.appendChild( all );

			cats.forEach( function ( cat ) {
				var active = ! allActive && state.selected.indexOf( cat.key ) !== -1;
				var pill = el( 'button', 'wpcc-pill' + ( active ? ' is-active' : '' ), iconHtml( cat.icon, 13 ) + '<span>' + escapeHtml( cat.label ) + '</span>' );
				pill.type = 'button';
				setCat( pill, cat.color );
				pill.addEventListener( 'click', function () {
					if ( allActive ) {
						// First pick solos that category.
						setSelected( [ cat.key ] );
					} else if ( active ) {
						setSelected( state.selected.filter( function ( k ) {
							return k !== cat.key;
						} ) );
					} else {
						var next = state.selected.concat( [ cat.key ] );
						// Selecting everything is the same as selecting nothing.
						setSelected( next.length === cats.length ? [] : next );
					}
					render();
				} );
				pills.appendChild( pill );
			} );

			wrap.appendChild( pills );
			return wrap;
		}

		function passesFilter( ev ) {
			return 0 === state.selected.length || state.selected.indexOf( ev.category ) !== -1;
		}

		/* ── Card view ─────────────────────────────────────────────────── */

		function renderCards() {
			var events = visibleEvents();
			var wrap = el( 'div' );

			if ( ! events.length ) {
				wrap.appendChild( el( 'div', 'wpcc-empty', escapeHtml( strings.noEvents || 'No upcoming events in the selected range.' ) ) );
				return wrap;
			}

			var cats = activeCategories( events );
			wrap.appendChild( renderFilters( cats ) );

			var kept = events.filter( passesFilter );
			if ( ! kept.length ) {
				wrap.appendChild( el( 'div', 'wpcc-empty', escapeHtml( strings.noMatches || 'No events match the selected filters.' ) ) );
				return wrap;
			}

			var byCategory = {};
			kept.forEach( function ( ev ) {
				( byCategory[ ev.category ] = byCategory[ ev.category ] || [] ).push( ev );
			} );

			var meta = {};
			cats.forEach( function ( cat ) {
				meta[ cat.key ] = cat;
			} );

			var groups = ( state.data && state.data.groups ) || [];
			if ( ! groups.length ) {
				groups = [ { label: '', keys: Object.keys( byCategory ) } ];
			}

			var todayMid = startOfToday();
			var rendered = {};

			groups.forEach( function ( group ) {
				var keys = group.keys.filter( function ( key ) {
					return byCategory[ key ] && byCategory[ key ].length;
				} );
				if ( ! keys.length ) {
					return;
				}
				wrap.appendChild( groupSection( group.label, keys, byCategory, meta, todayMid ) );
				keys.forEach( function ( key ) {
					rendered[ key ] = true;
				} );
			} );

			// Any category whose group is unknown still gets shown.
			var orphans = Object.keys( byCategory ).filter( function ( key ) {
				return ! rendered[ key ];
			} );
			if ( orphans.length ) {
				wrap.appendChild( groupSection( '', orphans, byCategory, meta, todayMid ) );
			}

			return wrap;
		}

		function groupSection( label, keys, byCategory, meta, todayMid ) {
			var section = el( 'div', 'wpcc-group-section' );
			if ( label ) {
				section.appendChild( el( 'div', 'wpcc-group-head', '<span>' + escapeHtml( label ) + '</span><i></i>' ) );
			}

			var grid = el( 'div', 'wpcc-card-grid' );
			keys.forEach( function ( key ) {
				grid.appendChild( categoryCard( key, byCategory[ key ], meta[ key ], todayMid ) );
			} );
			section.appendChild( grid );
			return section;
		}

		function categoryCard( key, events, cat, todayMid ) {
			cat = cat || { label: key, icon: '📌', color: '#5A5A5A' };

			// A multi-day event that's still running counts as current, not past.
			var future = events.filter( function ( ev ) {
				return eventLastDay( ev ) >= todayMid;
			} ).sort( function ( a, b ) {
				return parseLocal( a.start ) - parseLocal( b.start );
			} );
			var past = events.filter( function ( ev ) {
				return eventLastDay( ev ) < todayMid;
			} ).sort( function ( a, b ) {
				return parseLocal( b.start ) - parseLocal( a.start );
			} );

			var card = el( 'div', 'wpcc-card' );
			setCat( card, cat.color );

			card.appendChild( el( 'div', 'wpcc-card-head',
				iconHtml( cat.icon, 20 ) +
				'<span class="wpcc-card-label">' + escapeHtml( cat.label ) + '</span>' +
				// The hidden word makes this read as "5 events" rather than a
				// bare "5", and gives contrast checkers real text to measure
				// instead of a single digit they report as indeterminate.
				'<span class="wpcc-card-count">' + events.length +
					'<span class="wpcc-sr-only"> ' + escapeHtml(
						1 === events.length ?
							( strings.eventSingular || 'event' ) :
							( strings.eventPlural || 'events' )
					) + '</span></span>'
			) );

			var list = el( 'div', 'wpcc-card-list' );
			// Kept for the accessible name sizeCardLists() applies when this
			// list turns out to actually scroll.
			list.setAttribute( 'data-label', cat.label );
			if ( future.length > MAX_VISIBLE_ROWS || past.length ) {
				list.classList.add( 'is-scrollable' );
			}

			if ( ! events.length ) {
				list.appendChild( el( 'div', 'wpcc-empty-small', escapeHtml( strings.noneInRange || 'No events in this range' ) ) );
			} else {
				future.forEach( function ( ev ) {
					list.appendChild( eventRow( ev, cat.color ) );
				} );
				if ( past.length ) {
					list.appendChild( el( 'div', 'wpcc-divider', '<i></i><span>' + escapeHtml( strings.earlier || 'Earlier this period' ) + '</span><i></i>' ) );
					past.forEach( function ( ev ) {
						var row = eventRow( ev, cat.color );
						row.classList.add( 'is-past' );
						list.appendChild( row );
					} );
				}
			}

			card.appendChild( list );
			return card;
		}

		function eventRow( ev, color ) {
			var start = parseLocal( ev.start );
			var today = eventOccursOnDay( ev, new Date() );
			var multi = isMultiDay( ev );
			var last = eventLastDay( ev );
			var lastText = fmt( last, { month: 'short', day: 'numeric' }, locale );
			var dur = fmtDuration( ev.start, ev.end, ev.isAllDay );

			var row = el( 'div', 'wpcc-row' );
			setCat( row, color );

			var html = '<div class="wpcc-row-date">' +
					'<span class="wpcc-row-dow">' + escapeHtml( fmt( start, { weekday: 'short' }, locale ) ) + '</span>' +
					'<span class="wpcc-row-day">' + start.getDate() + '</span>' +
					'<span class="wpcc-row-mon">' + escapeHtml( fmt( start, { month: 'short' }, locale ) ) + '</span>' +
					( multi ? '<span class="wpcc-row-end"><span class="wpcc-sr-only">' + escapeHtml( strings.through || 'through' ) + ' </span>→ ' + escapeHtml( lastText ) + '</span>' : '' ) +
				'</div>' +
				'<div class="wpcc-row-info">' +
					'<div class="wpcc-row-title">' + escapeHtml( ev.title ) +
					( today ? ' <span class="wpcc-today-badge">' + escapeHtml( strings.today || 'Today' ) + '</span>' : '' ) +
					'</div>' +
					'<div class="wpcc-row-time">🕒 ' + escapeHtml( fmtTime( ev.start, ev.isAllDay, strings.allDay, locale ) ) +
					( ev.end && ! ev.isAllDay ? ' – ' + escapeHtml( fmtTime( ev.end, false, strings.allDay, locale ) ) : '' ) +
					( dur ? ' <span class="wpcc-chip-dur">' + escapeHtml( dur ) + '</span>' : '' ) +
					'</div>';

			if ( ev.location ) {
				html += '<div class="wpcc-row-loc">📍 ' + escapeHtml( ev.location ) + '</div>';
			}
			html += '</div>';

			row.innerHTML = html;
			bindPopup( row, ev );
			return row;
		}

		/* ── Calendar grid ─────────────────────────────────────────────── */

		function renderGrid() {
			var events = visibleEvents();
			var wrap = el( 'div' );
			var cats = activeCategories( events );

			wrap.appendChild( renderFilters( cats ) );

			var kept = events.filter( passesFilter );
			var range = alignedRange( state.unit, state.offset );

			var frame = el( 'div', 'wpcc-grid-frame' );
			frame.appendChild( el( 'div', 'wpcc-grid-title', escapeHtml( monthTitle( range.start, range.end ) ) ) );

			if ( 'day' === state.unit ) {
				frame.appendChild( dayView( kept, range.start ) );
			} else if ( 'week' === state.unit ) {
				frame.appendChild( weekGrid( kept, range.start ) );
			} else {
				frame.appendChild( monthGrid( kept, range.start ) );
			}

			wrap.appendChild( frame );
			return wrap;
		}

		function monthTitle( start, end ) {
			if ( isSameDay( start, end ) ) {
				return fmt( start, { month: 'long', year: 'numeric' }, locale );
			}
			var sameMonth = start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear();
			if ( sameMonth ) {
				return fmt( start, { month: 'long', year: 'numeric' }, locale );
			}
			if ( start.getFullYear() === end.getFullYear() ) {
				return fmt( start, { month: 'long' }, locale ) + ' – ' + fmt( end, { month: 'long', year: 'numeric' }, locale );
			}
			return fmt( start, { month: 'long', year: 'numeric' }, locale ) + ' – ' + fmt( end, { month: 'long', year: 'numeric' }, locale );
		}

		function dowHeader( days ) {
			var head = el( 'div', 'wpcc-dow-row' );
			for ( var i = 0; i < 7; i++ ) {
				var cell = el( 'div', 'wpcc-dow' + ( 0 === i || 6 === i ? ' is-weekend' : '' ) );
				var label = DOW[ i ];
				if ( days ) {
					var d = days[ i ];
					var isToday = isSameDay( d, new Date() );
					cell.innerHTML = '<span class="wpcc-dow-name">' + escapeHtml( label ) + '</span>' +
						'<span class="wpcc-dow-num' + ( isToday ? ' is-today' : '' ) + '">' + d.getDate() + '</span>';
				} else {
					cell.innerHTML = '<span class="wpcc-dow-name">' + escapeHtml( label ) + '</span>';
				}
				head.appendChild( cell );
			}
			return head;
		}

		/** Single-day agenda: a header for the day and its events stacked below. */
		function dayView( events, day ) {
			var isToday = isSameDay( day, new Date() );
			var grid = el( 'div', 'wpcc-grid is-day' );

			grid.appendChild( el( 'div', 'wpcc-dayview-head' + ( isToday ? ' is-today' : '' ),
				'<span class="wpcc-dow-name">' + escapeHtml( DOW[ day.getDay() ] ) + '</span>' +
				'<span class="wpcc-dow-num' + ( isToday ? ' is-today' : '' ) + '">' + day.getDate() + '</span>'
			) );

			var dayEvents = events.filter( function ( ev ) {
				return eventOccursOnDay( ev, day );
			} ).sort( function ( a, b ) {
				if ( a.isAllDay !== b.isAllDay ) {
					return a.isAllDay ? -1 : 1;
				}
				return parseLocal( a.start ) - parseLocal( b.start );
			} );

			var list = el( 'div', 'wpcc-dayview-list' );
			if ( ! dayEvents.length ) {
				list.appendChild( el( 'p', 'wpcc-dayview-empty', escapeHtml( strings.noEventsDay || 'No events scheduled for this day.' ) ) );
			} else {
				dayEvents.forEach( function ( ev ) {
					list.appendChild( eventChip( ev, day ) );
				} );
			}
			grid.appendChild( list );
			return grid;
		}

		function weekGrid( events, start ) {
			var days = [];
			for ( var i = 0; i < 7; i++ ) {
				var d = new Date( start );
				d.setDate( start.getDate() + i );
				days.push( d );
			}

			// is-week gives the single row room to breathe and lets the CSS drop the
			// per-cell date, since the header row already shows each day's number.
			var grid = el( 'div', 'wpcc-grid is-week' );
			grid.appendChild( dowHeader( days ) );

			var row = el( 'div', 'wpcc-week-row' );
			days.forEach( function ( day ) {
				row.appendChild( dayCell( day, events, true ) );
			} );
			grid.appendChild( row );
			return grid;
		}

		function monthGrid( events, start ) {
			var firstOfMonth = new Date( start.getFullYear(), start.getMonth(), 1 );
			var gridStart = new Date( firstOfMonth );
			gridStart.setDate( firstOfMonth.getDate() - firstOfMonth.getDay() );

			var grid = el( 'div', 'wpcc-grid' );
			grid.appendChild( dowHeader( null ) );

			for ( var week = 0; week < 6; week++ ) {
				var row = el( 'div', 'wpcc-week-row' );
				var hasAny = false;
				for ( var day = 0; day < 7; day++ ) {
					var d = new Date( gridStart );
					d.setDate( gridStart.getDate() + week * 7 + day );
					if ( d.getMonth() === start.getMonth() ) {
						hasAny = true;
					}
					row.appendChild( dayCell( d, events, d.getMonth() === start.getMonth() ) );
				}
				// A trailing all-other-month row adds nothing but height.
				if ( hasAny || week < 4 ) {
					grid.appendChild( row );
				}
			}
			return grid;
		}

		function dayCell( date, events, inMonth ) {
			var cell = el( 'div', 'wpcc-day' + ( inMonth ? '' : ' is-out' ) + ( isSameDay( date, new Date() ) ? ' is-today' : '' ) );

			var dayEvents = events.filter( function ( ev ) {
				return eventOccursOnDay( ev, date );
			} ).sort( function ( a, b ) {
				if ( a.isAllDay !== b.isAllDay ) {
					return a.isAllDay ? -1 : 1;
				}
				return parseLocal( a.start ) - parseLocal( b.start );
			} );

			cell.appendChild( el( 'div', 'wpcc-day-num', '<span>' + date.getDate() + '</span>' ) );

			var list = el( 'div', 'wpcc-chips' );
			dayEvents.forEach( function ( ev ) {
				list.appendChild( eventChip( ev, date ) );
			} );
			cell.appendChild( list );
			return cell;
		}

		function eventChip( ev, date ) {
			// Multi-day events render on every day they span; any day after the
			// start is a continuation.
			var cont = !! date && ! isSameDay( parseLocal( ev.start ), date );
			var chip = el( 'div', 'wpcc-chip' + ( cont ? ' is-cont' : '' ) );
			setCat( chip, ev.categoryColor );

			var time = '';
			if ( cont ) {
				var abbr = strings.continuedAbbr || "cont'd";
				time = '<span class="wpcc-chip-cont" aria-hidden="true">' + escapeHtml( abbr ) + '</span>' +
					'<span class="wpcc-sr-only">' + escapeHtml( ( strings.continued || 'continued' ) + ': ' ) + '</span>';
			} else if ( ! ev.isAllDay ) {
				time = '<span class="wpcc-chip-time">' +
					escapeHtml( fmtTime( ev.start, false, strings.allDay, locale ).replace( ':00', '' ).toLowerCase() ) +
					'</span>';
			}
			chip.innerHTML = '<span class="wpcc-chip-dot">' + ( cont ? '▸' : ev.isAllDay ? '●' : '◆' ) + '</span>' +
				'<span class="wpcc-chip-text">' + time + escapeHtml( ev.title ) + '</span>';

			bindPopup( chip, ev );
			return chip;
		}

		/* ── Search results ────────────────────────────────────────────── */

		function renderSearchResults() {
			var q = state.search.trim().toLowerCase();
			var matches = visibleEvents().filter( function ( ev ) {
				return ( ev.title || '' ).toLowerCase().indexOf( q ) !== -1 ||
					( ev.location || '' ).toLowerCase().indexOf( q ) !== -1 ||
					plainText( ev.description ).toLowerCase().indexOf( q ) !== -1 ||
					( ev.categoryLabel || '' ).toLowerCase().indexOf( q ) !== -1;
			} ).sort( function ( a, b ) {
				return parseLocal( a.start ) - parseLocal( b.start );
			} );

			var wrap = el( 'div' );

			if ( ! matches.length ) {
				wrap.appendChild( el( 'div', 'wpcc-search-count',
					escapeHtml( ( strings.searchNoneOne || 'No events match "%s" in the current view.' ).replace( '%s', state.search ) )
				) );
				wrap.appendChild( el( 'div', 'wpcc-empty', escapeHtml( strings.searchHint || '' ) ) );
				return wrap;
			}

			var count = ( strings.searchCount || '%1$d events match "%2$s" in the current view' )
				.replace( '%1$d', matches.length )
				.replace( '%2$s', state.search );
			wrap.appendChild( el( 'div', 'wpcc-search-count', escapeHtml( count ) ) );

			var list = el( 'div', 'wpcc-search-list' );
			matches.forEach( function ( ev ) {
				list.appendChild( detailCard( ev ) );
			} );
			wrap.appendChild( list );
			return wrap;
		}

		function detailCard( ev ) {
			var card = el( 'div', 'wpcc-detail' );
			setCat( card, ev.categoryColor );

			var html = '<div class="wpcc-detail-head">' +
					'<span class="wpcc-detail-cat">' + iconHtml( ev.categoryIcon, 13 ) + ' ' + escapeHtml( ev.categoryLabel ) + '</span>' +
					'<span class="wpcc-detail-when">' +
						escapeHtml( eventDateText( ev, locale ) ) + ' · ' +
						escapeHtml( fmtTime( ev.start, ev.isAllDay, strings.allDay, locale ) ) +
					'</span>' +
				'</div>' +
				'<div class="wpcc-detail-title">' + escapeHtml( ev.title ) + '</div>';

			if ( ev.location ) {
				html += '<div class="wpcc-detail-loc">📍 <a href="https://www.google.com/maps/search/?api=1&query=' +
					encodeURIComponent( ev.location ) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml( ev.location ) + '</a></div>';
			}
			if ( ev.description ) {
				html += '<div class="wpcc-detail-desc">' + linkify( ev.description ) + '</div>';
			}
			var alsoDetail = alsoListedBy( ev );
			if ( alsoDetail ) {
				html += '<div class="wpcc-detail-also">' + escapeHtml( alsoDetail ) + '</div>';
			}

			html += '<div class="wpcc-detail-links">';
			if ( ev.htmlLink ) {
				html += '<a href="' + escapeHtml( ev.htmlLink ) + '" target="_blank" rel="noopener noreferrer">↗ ' +
					escapeHtml( strings.openSource || 'Open source event' ) + '</a>';
			}
			html += '<a href="' + escapeHtml( addToCalendarUrl( ev, config.timezone ) ) + '" target="_blank" rel="noopener noreferrer">📅 ' +
				escapeHtml( strings.addToCalendar || 'Add to my calendar' ) + '</a></div>';

			card.innerHTML = html;
			return card;
		}

		/* ── Global listeners ──────────────────────────────────────────── */

		document.addEventListener( 'click', function ( e ) {
			if ( ! openPopup ) {
				return;
			}
			if ( openPopup.contains( e.target ) || root.contains( e.target ) ) {
				return;
			}
			closePopup();
		}, true );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && openPopup ) {
				closePopup();
			}
		} );

		window.addEventListener( 'resize', function () {
			closePopup();
			// Column count and title wrapping change with width, so re-measure.
			sizeCardLists();
		} );

		load();
	}

	function init() {
		var nodes = document.querySelectorAll( '.wpcc-app[data-wpcc-config]' );
		Array.prototype.forEach.call( nodes, function ( node ) {
			if ( node.getAttribute( 'data-wpcc-ready' ) ) {
				return;
			}
			node.setAttribute( 'data-wpcc-ready', '1' );
			Calendar( node );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
