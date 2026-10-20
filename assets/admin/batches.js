/**
 * Progressive enhancements for the "Share old posts" screen.
 *
 * The screen works without this file. It only keeps the share button from
 * confirming an outdated preview after the picks change, offers the
 * single-share timings again once the picks change, and refreshes a batch
 * page while PressQueue is still adding its shares.
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

	/*
	 * Post right away and Top of the Buffer queue are switched off while the
	 * preview has more than one share. Once other picks change, the next
	 * preview may have a single share, so offer them again.
	 */
	function reopenSingleShareTimings( form ) {
		var inputs = document.querySelectorAll( 'input[data-pq-single-timing]' );
		if ( ! inputs.length ) {
			return;
		}

		function onChange( event ) {
			var changed = event.target;
			var i;
			if ( ! changed || changed.form !== form ) {
				return;
			}

			for ( i = 0; i < inputs.length; i++ ) {
				var input = inputs[ i ];
				var label = input.closest ? input.closest( 'label' ) : null;
				var note = label ? label.querySelector( '[data-pq-single-note]' ) : null;

				if ( changed.name !== 'timing' ) {
					input.disabled = false;
					if ( label ) {
						label.classList.remove( 'pq-batch-timing-off' );
					}
				}

				if ( note && ! input.disabled && ! input.checked ) {
					note.hidden = true;
				}
			}
		}

		document.addEventListener( 'change', onChange );
		document.addEventListener( 'input', onChange );
	}

	function watchForChanges( root ) {
		var form = document.getElementById( 'pq-batch-form' );
		if ( form ) {
			reopenSingleShareTimings( form );
		}

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
