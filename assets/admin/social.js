/**
 * Social images settings: live preview and own background picker.
 */
( function () {
	'use strict';

	var config = window.pressqueueSocial || {};
	var form = document.querySelector( 'form.pq-social' );
	if ( ! form || ! config.previewUrl ) {
		return;
	}

	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( text ) {
		return text;
	};
	var image = form.querySelector( '[data-pq-social-image]' );
	var frame = image ? image.parentNode : null;
	var loading = form.querySelector( '[data-pq-social-loading]' );
	var sample = form.querySelector( '[data-pq-social-sample]' );
	var own = form.querySelector( '[data-pq-social-own]' );
	var timer = null;
	var pending = null;

	function value( name ) {
		var field = form.querySelector( '[name="' + name + '"]:checked' ) || form.querySelector( '[name="' + name + '"]:not([type="radio"]):not([type="checkbox"])' );
		return field ? field.value : '';
	}

	function title() {
		if ( sample && sample.value === '__own' ) {
			return own && own.value.trim() !== '' ? own.value.trim() : __( 'Your post title', 'pressqueue' );
		}
		return sample ? sample.value : '';
	}

	function previewUrl() {
		var params = new URLSearchParams();
		params.set( 'title', title() );
		[ 'background', 'custom_image', 'font', 'text', 'align', 'site_label' ].forEach( function ( name ) {
			params.set( name, value( name ) );
		} );
		var showSite = form.querySelector( '[name="show_site"]' );
		params.set( 'show_site', showSite && showSite.checked ? '1' : '' );
		return config.previewUrl + '&' + params.toString();
	}

	function refresh() {
		if ( ! image ) {
			return;
		}
		var url = previewUrl();
		if ( url === pending ) {
			return;
		}
		pending = url;
		frame.classList.add( 'is-loading' );
		if ( loading ) {
			loading.hidden = false;
		}
		var next = new Image();
		next.onload = function () {
			if ( pending !== url ) {
				return;
			}
			image.src = url;
			frame.classList.remove( 'is-loading' );
			if ( loading ) {
				loading.hidden = true;
			}
		};
		next.onerror = function () {
			frame.classList.remove( 'is-loading' );
			if ( loading ) {
				loading.hidden = true;
			}
		};
		next.src = url;
	}

	function schedule( delay ) {
		window.clearTimeout( timer );
		timer = window.setTimeout( refresh, delay );
	}

	form.addEventListener( 'change', function ( event ) {
		if ( event.target === sample && own ) {
			own.hidden = sample.value !== '__own';
			if ( ! own.hidden ) {
				own.focus();
			}
		}
		if ( event.target.matches( '[data-pq-social-show-site]' ) ) {
			var box = form.querySelector( '[data-pq-social-site-label]' );
			if ( box ) {
				box.hidden = ! event.target.checked;
			}
		}
		schedule( 60 );
	} );

	form.addEventListener( 'input', function ( event ) {
		if ( event.target === own || event.target.name === 'site_label' ) {
			schedule( 400 );
		}
	} );

	// Own background picked from the Media Library.
	var upload = form.querySelector( '[data-pq-social-upload]' );
	if ( upload && window.wp && window.wp.media ) {
		var picker = null;
		upload.addEventListener( 'click', function () {
			if ( ! picker ) {
				picker = window.wp.media( {
					title: __( 'Choose a background image', 'pressqueue' ),
					button: { text: __( 'Use this image', 'pressqueue' ) },
					library: { type: 'image' },
					multiple: false,
				} );
				picker.on( 'select', function () {
					var attachment = picker.state().get( 'selection' ).first().toJSON();
					var idField = form.querySelector( '[data-pq-social-custom-id]' );
					var radio = form.querySelector( '[data-pq-social-custom-radio]' );
					var thumb = form.querySelector( '[data-pq-social-custom-thumb]' );
					var sizes = attachment.sizes || {};
					var src = ( sizes.medium || sizes.full || attachment ).url;
					idField.value = String( attachment.id );
					if ( thumb ) {
						thumb.src = src;
						thumb.hidden = false;
					}
					radio.disabled = false;
					radio.checked = true;
					upload.textContent = __( 'Choose another image', 'pressqueue' );
					schedule( 0 );
				} );
			}
			picker.open();
		} );
	}
}() );
