( function ( wp ) {
	'use strict';

	var __ = wp && wp.i18n ? wp.i18n.__ : function ( text ) {
		return text;
	};
	var sprintf = wp && wp.i18n ? wp.i18n.sprintf : function ( format ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var index = 0;
		return String( format ).replace( /%(\d+\$)?[ds]/g, function () {
			return String( args[ index++ ] );
		} );
	};

	function each( root, selector, callback ) {
		Array.prototype.forEach.call( root.querySelectorAll( selector ), callback );
	}

	function setupShareSwitch( box ) {
		var input = box.querySelector( '[data-pq-share]' );
		if ( ! input ) {
			return;
		}
		function sync() {
			each( box, '[data-pq-when]', function ( node ) {
				node.hidden = node.getAttribute( 'data-pq-when' ) !== ( input.checked ? 'on' : 'off' );
			} );
		}
		input.addEventListener( 'change', sync );
		sync();
	}

	function setupDependentFields( box ) {
		each( box, '[data-pq-toggle-for]', function ( select ) {
			var group = select.getAttribute( 'data-pq-toggle-for' );
			function sync() {
				each( box, '[data-pq-show-for^="' + group + ':"]', function ( node ) {
					node.hidden = node.getAttribute( 'data-pq-show-for' ) !== group + ':' + select.value;
				} );
			}
			select.addEventListener( 'change', sync );
			sync();
		} );
	}

	function setupCounter( box ) {
		var textarea = box.querySelector( '[data-pq-message]' );
		var output = box.querySelector( '[data-pq-count]' );
		if ( ! textarea || ! output ) {
			return;
		}
		var limit = parseInt( textarea.getAttribute( 'data-limit' ), 10 ) || 0;
		var urlLength = parseInt( textarea.getAttribute( 'data-url-length' ), 10 ) || 0;
		var service = textarea.getAttribute( 'data-limit-service' ) || '';
		function sync() {
			if ( ! limit ) {
				output.textContent = '';
				return;
			}
			var text = String( textarea.value || '' ).replace( /\s+/g, ' ' ).trim() || textarea.getAttribute( 'placeholder' ) || '';
			var used = Array.from( text ).length + 2 + urlLength;
			if ( used <= limit ) {
				/* translators: 1: characters used, 2: character limit. */
				output.textContent = sprintf( __( '%1$d of %2$d characters, including the link.', 'pressqueue' ), used, limit );
				output.classList.remove( 'is-over' );
				return;
			}
			/* translators: 1: number of characters over the limit, 2: social network name. */
			output.textContent = sprintf( __( '%1$d characters too long for %2$s. The link counts too.', 'pressqueue' ), used - limit, service );
			output.classList.add( 'is-over' );
		}
		output.classList.add( 'pq-ed-count' );
		textarea.addEventListener( 'input', sync );
		sync();
	}

	function setupMediaPicker( box ) {
		var button = box.querySelector( '[data-pq-media-pick]' );
		var input = box.querySelector( '[data-pq-media-ids]' );
		var list = box.querySelector( '[data-pq-media-list]' );
		if ( ! button || ! input || ! list ) {
			return;
		}
		if ( ! wp || ! wp.media ) {
			button.hidden = true;
			return;
		}
		var frame = null;
		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			if ( ! frame ) {
				frame = wp.media( {
					title: __( 'Choose images to share', 'pressqueue' ),
					button: { text: __( 'Use these images', 'pressqueue' ) },
					library: { type: 'image' },
					multiple: true
				} );
				frame.on( 'open', function () {
					var selection = frame.state().get( 'selection' );
					selection.reset();
					String( input.value || '' ).split( ',' ).forEach( function ( id ) {
						var attachment = id ? wp.media.attachment( parseInt( id, 10 ) ) : null;
						if ( attachment ) {
							attachment.fetch();
							selection.add( attachment );
						}
					} );
				} );
				frame.on( 'select', function () {
					var picked = frame.state().get( 'selection' ).toJSON().slice( 0, 4 );
					input.value = picked.map( function ( item ) {
						return item.id;
					} ).join( ',' );
					list.textContent = '';
					picked.forEach( function ( item ) {
						var li = document.createElement( 'li' );
						var img = document.createElement( 'img' );
						var sizes = item.sizes || {};
						img.src = sizes.thumbnail ? sizes.thumbnail.url : item.url;
						img.alt = item.alt || '';
						li.appendChild( img );
						list.appendChild( li );
					} );
				} );
			}
			frame.open();
		} );
	}

	function setup( box ) {
		setupShareSwitch( box );
		setupDependentFields( box );
		setupCounter( box );
		setupMediaPicker( box );
	}

	function init() {
		each( document, '[data-pq-classic]', setup );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}( window.wp ) );
