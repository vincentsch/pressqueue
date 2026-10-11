/**
 * Progressive enhancements for the "Share old posts" screen.
 *
 * The screen works without this file. It only keeps the share button from
 * confirming an outdated preview after the picks change, and refreshes a
 * batch page while PressQueue is still adding its shares.
 */
( function () {
	'use strict';

	var MAX_REFRESHES = 30;

	function ready( callback ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', callback );
		} else {
			callback();
		}
	}

	function watchForChanges( root ) {
		var form = document.getElementById( 'pq-batch-form' );
		var confirmButton = root.querySelector( '[data-pq-batch-confirm]' );
		if ( ! form || ! confirmButton ) {
			return;
		}

		var updateButton = root.querySelector( '[data-pq-batch-update]' );
		var note = root.querySelector( '[data-pq-batch-stale]' );

		function markStale( event ) {
			var input = event.target;
			if ( ! input || input.form !== form ) {
				return;
			}
			confirmButton.disabled = true;
			confirmButton.setAttribute( 'aria-describedby', 'pq-batch-stale-note' );
			if ( note ) {
				note.id = 'pq-batch-stale-note';
				note.hidden = false;
			}
			if ( updateButton ) {
				updateButton.classList.add( 'button-primary' );
			}
		}

		document.addEventListener( 'change', markStale );
		document.addEventListener( 'input', markStale );
	}

	function refreshWhileBusy( root ) {
		var seconds = parseInt( root.getAttribute( 'data-pq-refresh' ) || '', 10 );
		if ( ! seconds || ! window.URL ) {
			return;
		}

		var url = new window.URL( window.location.href );
		var count = parseInt( url.searchParams.get( 'pq_refresh' ) || '0', 10 );
		if ( count >= MAX_REFRESHES ) {
			return;
		}

		function reload() {
			url.searchParams.set( 'pq_refresh', String( count + 1 ) );
			url.searchParams.delete( 'pressqueue_message' );
			window.location.replace( url.toString() );
		}

		window.setTimeout( function () {
			if ( ! document.hidden ) {
				reload();
				return;
			}
			document.addEventListener( 'visibilitychange', function () {
				if ( ! document.hidden ) {
					reload();
				}
			} );
		}, seconds * 1000 );
	}

	ready( function () {
		var root = document.querySelector( '.pq-admin .pq-batch' );
		if ( ! root ) {
			return;
		}

		watchForChanges( root );
		refreshWhileBusy( root );
	} );
}() );
