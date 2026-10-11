/**
 * Small progressive enhancements shared by PressQueue administration screens.
 *
 * Every screen works without this file. It only submits toggle switches on
 * change, asks for confirmation before destructive actions, and keeps double
 * clicks from submitting a form twice.
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
		document.querySelectorAll( '.pq-admin.pq-no-js' ).forEach( function ( root ) {
			root.classList.remove( 'pq-no-js' );
		} );

		document.addEventListener( 'change', function ( event ) {
			var input = event.target;
			if ( ! input.matches || ! input.matches( '.pq-toggle-form input[type="checkbox"]' ) ) {
				return;
			}
			var form = input.form;
			if ( ! form ) {
				return;
			}
			if ( form.dataset.pqConfirm && ! window.confirm( form.dataset.pqConfirm ) ) {
				input.checked = ! input.checked;
				return;
			}
			form.dataset.pqConfirmed = '1';
			if ( typeof form.requestSubmit === 'function' ) {
				form.requestSubmit();
			} else {
				form.submit();
			}
		} );

		document.addEventListener( 'submit', function ( event ) {
			var form = event.target;
			if ( ! form.closest || ! form.closest( '.pq-admin' ) ) {
				return;
			}
			var submitter = event.submitter;
			var message = ( submitter && submitter.dataset.pqConfirm ) || form.dataset.pqConfirm;
			if ( message && form.dataset.pqConfirmed !== '1' && ! window.confirm( message ) ) {
				event.preventDefault();
				return;
			}
			delete form.dataset.pqConfirmed;
			// Downloads never leave the page, so they may be submitted again right away.
			if ( form.dataset.pqDownload === '1' ) {
				return;
			}
			if ( form.dataset.pqSubmitting === '1' ) {
				event.preventDefault();
				return;
			}
			form.dataset.pqSubmitting = '1';
			window.setTimeout( function () {
				delete form.dataset.pqSubmitting;
			}, 4000 );
		} );
	} );
}() );
