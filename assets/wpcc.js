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
	var MAX_VISIBLE_ROWS = 5;

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
		if ( 'week' === unit ) {
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
		var lastOfMonth = new Date( end.getFullYear(), end.getMonth() + 1, 0 ).getDate();
		if ( 1 === start.getDate() && end.getDate() === lastOfMonth && start.getMonth() === end.getMonth() ) {
			return fmt( start, { month: 'long', year: 'numeric' }, locale );
		}
		return fmt( start, { month: 'short', day: 'numeric' }, locale ) + ' – ' +
			fmt( end, { month: 'short', day: 'numeric', year: 'numeric' }, locale );
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

	function iconHtml( icon, size ) {
		if ( ! icon ) {
			return '';
		}
		if ( /^(https?:\/\/|\/|data:image\/)/.test( String( icon ).trim() ) ) {
			return '<img class="wpcc-icon-img" src="' + escapeHtml( icon ) + '" alt="" aria-hidden="true" style="width:' + size + 'px;height:' + size + 'px" />';
		}
		return '<span class="wpcc-icon" style="font-size:' + Math.round( size * 0.9 ) + 'px">' + escapeHtml( icon ) + '</span>';
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
			search: '',
			selected: [],
			loading: true,
			error: null,
			data: null,
			fetchedAt: null
		};

		var openPopup = null;
		var hoverTimer = null;
		var hideTimer = null;
		var requestId = 0;

		root.style.setProperty( '--wpcc-accent', config.accent || '#147671' );
		root.style.setProperty( '--wpcc-highlight', config.highlight || '#CF2C28' );

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
			var start = parseLocal( ev.start );

			// The popup lives on <body> (so no theme wrapper can clip it), which
			// means it has to carry the theme class and accent vars itself.
			var popup = el( 'div', 'wpcc-popup wpcc-theme-' + state.theme );
			popup.style.setProperty( '--wpcc-cat', color );
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
							fmt( start, { weekday: 'short' }, locale ) + ', ' + fmt( start, { month: 'short' }, locale ) + ' ' + start.getDate() +
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

			// Week / month.
			var unitGroup = el( 'div', 'wpcc-group' );
			[ [ 'week', strings.week || 'Week' ], [ 'month', strings.month || 'Month' ] ].forEach( function ( pair ) {
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
			nav.appendChild( button( '‹', strings.prev || 'Previous', 'wpcc-icon-btn', function () {
				state.offset -= 1;
				load();
			} ) );
			nav.appendChild( button( escapeHtml( strings.today || 'Today' ), null, '', function () {
				state.offset = 0;
				load();
			} ) );
			nav.appendChild( button( '›', strings.next || 'Next', 'wpcc-icon-btn', function () {
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

			bar.appendChild( button( '⟳', strings.refresh || 'Refresh', 'wpcc-icon-btn', function () {
				load();
			} ) );

			if ( config.showThemeToggle ) {
				bar.appendChild( button(
					'dark' === state.theme ? '☀' : '☾',
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
			wrap.innerHTML = '<span class="wpcc-search-ico" aria-hidden="true">🔍</span>';

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

			wrap.appendChild( el( 'div', 'wpcc-filter-tip', escapeHtml( strings.filterTip || '' ) ) );

			var pills = el( 'div', 'wpcc-pills' );
			var allActive = 0 === state.selected.length;

			var all = el( 'button', 'wpcc-pill' + ( allActive ? ' is-active' : '' ), escapeHtml( strings.all || 'All' ) );
			all.type = 'button';
			all.addEventListener( 'click', function () {
				state.selected = [];
				render();
			} );
			pills.appendChild( all );

			cats.forEach( function ( cat ) {
				var active = ! allActive && state.selected.indexOf( cat.key ) !== -1;
				var pill = el( 'button', 'wpcc-pill' + ( active ? ' is-active' : '' ), iconHtml( cat.icon, 13 ) + '<span>' + escapeHtml( cat.label ) + '</span>' );
				pill.type = 'button';
				pill.style.setProperty( '--wpcc-cat', cat.color );
				pill.addEventListener( 'click', function () {
					if ( allActive ) {
						// First pick solos that category.
						state.selected = [ cat.key ];
					} else if ( active ) {
						state.selected = state.selected.filter( function ( k ) {
							return k !== cat.key;
						} );
					} else {
						state.selected = state.selected.concat( [ cat.key ] );
						// Selecting everything is the same as selecting nothing.
						if ( state.selected.length === cats.length ) {
							state.selected = [];
						}
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

			var future = events.filter( function ( ev ) {
				return parseLocal( ev.start ) >= todayMid;
			} ).sort( function ( a, b ) {
				return parseLocal( a.start ) - parseLocal( b.start );
			} );
			var past = events.filter( function ( ev ) {
				return parseLocal( ev.start ) < todayMid;
			} ).sort( function ( a, b ) {
				return parseLocal( b.start ) - parseLocal( a.start );
			} );

			var card = el( 'div', 'wpcc-card' );
			card.style.setProperty( '--wpcc-cat', cat.color );

			card.appendChild( el( 'div', 'wpcc-card-head',
				iconHtml( cat.icon, 20 ) +
				'<span class="wpcc-card-label">' + escapeHtml( cat.label ) + '</span>' +
				'<span class="wpcc-card-count">' + events.length + '</span>'
			) );

			var list = el( 'div', 'wpcc-card-list' );
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
			var today = isSameDay( start, new Date() );
			var dur = fmtDuration( ev.start, ev.end, ev.isAllDay );

			var row = el( 'div', 'wpcc-row' );
			row.style.setProperty( '--wpcc-cat', color );

			var html = '<div class="wpcc-row-date">' +
					'<span class="wpcc-row-dow">' + escapeHtml( fmt( start, { weekday: 'short' }, locale ) ) + '</span>' +
					'<span class="wpcc-row-day">' + start.getDate() + '</span>' +
					'<span class="wpcc-row-mon">' + escapeHtml( fmt( start, { month: 'short' }, locale ) ) + '</span>' +
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

			if ( 'week' === state.unit ) {
				frame.appendChild( weekGrid( kept, range.start ) );
			} else {
				frame.appendChild( monthGrid( kept, range.start ) );
			}

			wrap.appendChild( frame );
			return wrap;
		}

		function monthTitle( start, end ) {
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
				return isSameDay( parseLocal( ev.start ), date );
			} ).sort( function ( a, b ) {
				if ( a.isAllDay !== b.isAllDay ) {
					return a.isAllDay ? -1 : 1;
				}
				return parseLocal( a.start ) - parseLocal( b.start );
			} );

			cell.appendChild( el( 'div', 'wpcc-day-num', '<span>' + date.getDate() + '</span>' ) );

			var list = el( 'div', 'wpcc-chips' );
			dayEvents.forEach( function ( ev ) {
				list.appendChild( eventChip( ev ) );
			} );
			cell.appendChild( list );
			return cell;
		}

		function eventChip( ev ) {
			var chip = el( 'div', 'wpcc-chip' );
			chip.style.setProperty( '--wpcc-cat', ev.categoryColor );

			var time = '';
			if ( ! ev.isAllDay ) {
				time = '<span class="wpcc-chip-time">' +
					escapeHtml( fmtTime( ev.start, false, strings.allDay, locale ).replace( ':00', '' ).toLowerCase() ) +
					'</span>';
			}
			chip.innerHTML = '<span class="wpcc-chip-dot">' + ( ev.isAllDay ? '●' : '◆' ) + '</span>' +
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
			var start = parseLocal( ev.start );
			var card = el( 'div', 'wpcc-detail' );
			card.style.setProperty( '--wpcc-cat', ev.categoryColor );

			var html = '<div class="wpcc-detail-head">' +
					'<span class="wpcc-detail-cat">' + iconHtml( ev.categoryIcon, 13 ) + ' ' + escapeHtml( ev.categoryLabel ) + '</span>' +
					'<span class="wpcc-detail-when">' +
						escapeHtml( fmt( start, { weekday: 'short', month: 'short', day: 'numeric' }, locale ) ) + ' · ' +
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

		window.addEventListener( 'resize', closePopup );

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
