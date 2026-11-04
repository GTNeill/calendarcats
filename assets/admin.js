/**
 * WPCalendarCats — category editor behaviour.
 *
 * Plain DOM, no dependencies. Rows are added by cloning the
 * <script type="text/template"> markup PHP renders and swapping the
 * __INDEX__ placeholder for a fresh index, so every field keeps a unique
 * wpcc_cats[n][…] name. Order in the DOM is the saved order.
 */
( function () {
	'use strict';

	var rows;
	var template;
	var nextIndex = 0;

	function catRows() {
		return Array.prototype.slice.call( rows.querySelectorAll( '.wpcc-cat-row' ) );
	}

	/** Reindex every field name so removals never leave gaps or collisions. */
	function reindex() {
		catRows().forEach( function ( row, i ) {
			row.querySelectorAll( '[name^="wpcc_cats["]' ).forEach( function ( field ) {
				field.name = field.name.replace( /^wpcc_cats\[[^\]]*\]/, 'wpcc_cats[' + i + ']' );
			} );
		} );
	}

	var idCounter = 0;

	/**
	 * Give a row what assistive tech needs: the move/remove buttons name the
	 * category they act on, and Edit points at the panel it opens.
	 */
	function decorate( row ) {
		var name = ( ( row.querySelector( '.wpcc-cat-title' ) || {} ).textContent || '' ).trim();
		[ '.wpcc-move-up', '.wpcc-move-down', '.wpcc-remove' ].forEach( function ( sel ) {
			var btn = row.querySelector( sel );
			if ( ! btn ) {
				return;
			}
			if ( ! btn.hasAttribute( 'data-base-label' ) ) {
				btn.setAttribute( 'data-base-label', btn.getAttribute( 'aria-label' ) || btn.title || '' );
			}
			btn.setAttribute( 'aria-label', btn.getAttribute( 'data-base-label' ) + ( name ? ': ' + name : '' ) );
		} );

		var body = row.querySelector( '.wpcc-cat-body' );
		var toggle = row.querySelector( '.wpcc-toggle' );
		if ( body && toggle ) {
			if ( ! body.id ) {
				idCounter += 1;
				body.id = 'wpcc-cat-body-' + idCounter;
			}
			toggle.setAttribute( 'aria-controls', body.id );
		}
	}

	function toggleBody( row, force ) {
		var body = row.querySelector( '.wpcc-cat-body' );
		var btn = row.querySelector( '.wpcc-toggle' );
		if ( ! body ) {
			return;
		}
		var open = undefined === force ? body.hidden : force;
		body.hidden = ! open;
		if ( btn ) {
			btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
		row.classList.toggle( 'is-open', open );
	}

	function updateBadge( row ) {
		var textarea = row.querySelector( 'textarea[name*="[keywords]"]' );
		var badge = row.querySelector( '.wpcc-cat-badge' );
		if ( ! textarea || ! badge ) {
			return;
		}
		var count = textarea.value.split( '\n' ).filter( function ( line ) {
			return '' !== line.trim();
		} ).length;
		var suffix = badge.textContent.replace( /^\s*\d+\s*/, '' );
		badge.textContent = count + ' ' + suffix;
	}

	function move( row, dir ) {
		if ( dir < 0 && row.previousElementSibling ) {
			rows.insertBefore( row, row.previousElementSibling );
		} else if ( dir > 0 && row.nextElementSibling ) {
			rows.insertBefore( row.nextElementSibling, row );
		}
		reindex();
		row.scrollIntoView( { block: 'nearest' } );
		// The buttons move with the row; keep focus on the one just pressed.
		var active = document.activeElement;
		if ( active && active.focus && row.contains( active ) ) {
			active.focus();
		}
	}

	function addRow() {
		var html = template.innerHTML.replace( /__INDEX__/g, String( nextIndex ) );
		nextIndex += 1;

		var holder = document.createElement( 'div' );
		holder.innerHTML = html;

		var row = holder.querySelector( '.wpcc-cat-row' );
		if ( ! row ) {
			return;
		}
		rows.appendChild( row );
		reindex();
		decorate( row );
		toggleBody( row, true );

		var first = row.querySelector( '.wpcc-label-input' );
		if ( first ) {
			first.focus();
		}
		row.scrollIntoView( { block: 'center', behavior: 'smooth' } );
	}

	function onClick( e ) {
		var button = e.target.closest( 'button' );
		if ( ! button ) {
			return;
		}
		var row = button.closest( '.wpcc-cat-row' );

		if ( 'wpcc-add-cat' === button.id ) {
			e.preventDefault();
			addRow();
			return;
		}
		if ( ! row ) {
			return;
		}

		if ( button.classList.contains( 'wpcc-toggle' ) ) {
			e.preventDefault();
			toggleBody( row );
		} else if ( button.classList.contains( 'wpcc-move-up' ) ) {
			e.preventDefault();
			move( row, -1 );
		} else if ( button.classList.contains( 'wpcc-move-down' ) ) {
			e.preventDefault();
			move( row, 1 );
		} else if ( button.classList.contains( 'wpcc-remove' ) ) {
			e.preventDefault();
			var label = ( row.querySelector( '.wpcc-cat-title' ) || {} ).textContent || '';
			var message = ( window.wpccAdmin && window.wpccAdmin.confirmRemove ) || 'Remove "%s"?';
			if ( window.confirm( message.replace( '%s', label.trim() ) ) ) {
				row.parentNode.removeChild( row );
				reindex();
			}
		}
	}

	/** Live-sync the collapsed header with what is being typed below it. */
	function onInput( e ) {
		var field = e.target;
		var row = field.closest ? field.closest( '.wpcc-cat-row' ) : null;
		if ( ! row ) {
			return;
		}

		if ( field.classList.contains( 'wpcc-label-input' ) ) {
			var title = row.querySelector( '.wpcc-cat-title' );
			if ( title ) {
				title.textContent = field.value.trim() || ( ( window.wpccAdmin && window.wpccAdmin.newCategory ) || 'New category' );
				decorate( row );
			}
		} else if ( field.classList.contains( 'wpcc-color-input' ) ) {
			var swatch = row.querySelector( '.wpcc-cat-swatch' );
			if ( swatch ) {
				swatch.style.background = field.value.trim() || 'transparent';
			}
		} else if ( 'TEXTAREA' === field.tagName && /\[keywords\]/.test( field.name || '' ) ) {
			updateBadge( row );
		}
	}

	function init() {
		rows = document.getElementById( 'wpcc-cat-rows' );
		template = document.getElementById( 'wpcc-cat-template' );
		if ( ! rows || ! template ) {
			return;
		}

		nextIndex = catRows().length;

		// Clicking a collapsed header is a bigger target than the Edit link.
		rows.addEventListener( 'dblclick', function ( e ) {
			var head = e.target.closest( '.wpcc-cat-head' );
			if ( head && ! e.target.closest( 'button' ) ) {
				toggleBody( head.parentNode );
			}
		} );

		document.addEventListener( 'click', onClick );
		document.addEventListener( 'input', onInput );

		catRows().forEach( decorate );
		reindex();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
