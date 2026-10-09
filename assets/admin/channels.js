/**
 * Channels screen enhancements.
 *
 * The page works without this file. It closes an open "More" menu when you
 * click outside it or press Escape, and shows that Connect is working.
 */
( function () {
	'use strict';

	function ready( callback ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', callback );
		} else {
			callback();
		}
	}

	ready( function () {
		var root = document.querySelector( '.pq-admin' );
		if ( ! root ) {
			return;
		}

		function closeMenus( except ) {
			root.querySelectorAll( 'details.pq-menu[open]' ).forEach( function ( menu ) {
				if ( menu !== except ) {
					menu.open = false;
				}
			} );
		}

		document.addEventListener( 'click', function ( event ) {
			var menu = event.target.closest ? event.target.closest( 'details.pq-menu' ) : null;
			closeMenus( menu );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key !== 'Escape' ) {
				return;
			}
			var open = root.querySelector( 'details.pq-menu[open]' );
			if ( open ) {
				open.open = false;
				var summary = open.querySelector( 'summary' );
				if ( summary ) {
					summary.focus();
				}
			}
		} );

		// Registered after admin.js, so a cancelled confirmation has already prevented the submit.
		document.addEventListener( 'submit', function ( event ) {
			var form = event.target;
			if ( event.defaultPrevented || ! form.matches || ! form.matches( '.pq-admin form' ) ) {
				return;
			}
			var button = event.submitter || form.querySelector( 'button[type="submit"]' );
			if ( button && button.dataset.pqBusy ) {
				button.textContent = button.dataset.pqBusy;
				button.setAttribute( 'aria-disabled', 'true' );
			}
		} );
	} );
}() );
