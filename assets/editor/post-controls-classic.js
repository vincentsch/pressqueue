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
					title: __( 'Choose an image to share', 'pressqueue' ),
					button: { text: __( 'Use this image', 'pressqueue' ) },
					library: { type: 'image' },
					multiple: false
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
					var picked = frame.state().get( 'selection' ).toJSON().slice( 0, 1 );
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

	function node( tag, className, text ) {
		var element = document.createElement( tag );
		if ( className ) {
			element.className = className;
		}
		if ( text ) {
			element.textContent = text;
		}
		return element;
	}

	function channelName( channel ) {
		var wrap = node( 'span', 'pq-channel-name' );
		var badge = node( 'span', 'pq-svc pq-svc--sm pq-svc--' + String( channel.service || '' ).replace( /[^a-z0-9-]/g, '' ), channel.monogram || '' );
		badge.setAttribute( 'aria-hidden', 'true' );
		wrap.appendChild( badge );
		wrap.appendChild( node( 'span', 'pq-truncate', channel.name || channel.service_name || '' ) );
		wrap.appendChild( node( 'span', 'screen-reader-text', ' (' + ( channel.service_name || '' ) + ')' ) );
		return wrap;
	}

	function errorMessage( error ) {
		return error && error.message ? error.message : __( 'PressQueue could not do that. Check your connection and try again.', 'pressqueue' );
	}

	// "Share now" for a published post that was never shared. It shows exactly what would be
	// sent and only sends after the confirm button is clicked.
	function setupShareNow( box ) {
		var root = box.querySelector( '[data-pq-share-now]' );
		if ( ! root || ! wp || typeof wp.apiFetch !== 'function' ) {
			return;
		}
		var path = '/pressqueue/v1/posts/' + parseInt( root.getAttribute( 'data-post-id' ), 10 ) + '/share-now';
		var savedOn = root.getAttribute( 'data-saved-on' ) === '1';
		var toggle = box.querySelector( '[data-pq-share]' );
		var start = root.querySelector( '[data-pq-share-now-start]' );
		var help = root.querySelector( '[data-pq-share-now-help]' );
		var unsaved = root.querySelector( '[data-pq-share-now-unsaved]' );
		var result = root.querySelector( '[data-pq-share-now-result]' );
		var preview = null;
		var busy = false;

		function sync() {
			var changed = !! toggle && toggle.checked !== savedOn;
			start.disabled = busy || changed;
			start.parentNode.hidden = !! preview;
			unsaved.hidden = ! changed;
			help.hidden = changed || !! preview;
		}

		function show( children ) {
			result.textContent = '';
			children.forEach( function ( child ) {
				if ( child ) {
					result.appendChild( child );
				}
			} );
		}

		function problem( text ) {
			return node( 'p', 'pq-ed-share-now-problem', text );
		}

		function forget() {
			if ( preview && preview.batch_public_id ) {
				wp.apiFetch( { path: path + '/cancel', method: 'POST', data: { batch_public_id: preview.batch_public_id } } ).catch( function () {} );
			}
			preview = null;
		}

		function button( label, className, onClick ) {
			var element = node( 'button', className, label );
			element.type = 'button';
			element.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				onClick( element );
			} );
			return element;
		}

		function renderPreview( data ) {
			var panel = node( 'div', 'pq-ed-share-now-preview' );
			var list = node( 'ul', 'pq-ed-share-now-list' );
			var actions = node( 'p', 'pq-ed-share-now-actions' );
			panel.appendChild( node( 'p', 'pq-ed-small pq-ed-muted', data.heading || '' ) );
			( data.shares || [] ).forEach( function ( share ) {
				var item = node( 'li' );
				var head = node( 'div', 'pq-ed-share-now-head' );
				head.appendChild( channelName( share ) );
				head.appendChild( node( 'span', 'pq-ed-small pq-ed-muted', share.when || '' ) );
				item.appendChild( head );
				item.appendChild( node( 'p', 'pq-ed-share-text', share.text || '' ) );
				if ( share.image_url ) {
					var image = document.createElement( 'img' );
					image.className = 'pq-ed-share-image';
					image.src = share.image_url;
					image.alt = '';
					item.appendChild( image );
				}
				item.appendChild( node( 'p', 'pq-ed-small pq-ed-muted', share.image || '' ) );
				list.appendChild( item );
			} );
			if ( list.childNodes.length ) {
				panel.appendChild( list );
			}
			( data.skipped || [] ).forEach( function ( channel ) {
				/* translators: 1: channel name, 2: reason. */
				panel.appendChild( node( 'p', 'pq-ed-small pq-ed-muted', sprintf( __( 'Not sent to %1$s: %2$s', 'pressqueue' ), channel.name, channel.reason ) ) );
			} );
			if ( data.note ) {
				panel.appendChild( node( 'p', 'pq-ed-small pq-ed-muted', data.note ) );
			}
			if ( data.problem ) {
				panel.appendChild( problem( data.problem ) );
			}
			if ( data.can_confirm ) {
				actions.appendChild( button( __( 'Confirm and share', 'pressqueue' ), 'button button-primary', confirm ) );
			}
			actions.appendChild( button( data.can_confirm ? __( 'Cancel', 'pressqueue' ) : __( 'Close', 'pressqueue' ), 'button-link', function () {
				forget();
				show( [] );
				sync();
				start.focus();
			} ) );
			panel.appendChild( actions );
			show( [ panel ] );
		}

		function confirm( element ) {
			if ( busy || ! preview ) {
				return;
			}
			busy = true;
			element.disabled = true;
			element.textContent = __( 'Sharing…', 'pressqueue' );
			wp.apiFetch( {
				path: path,
				method: 'POST',
				data: { batch_public_id: preview.batch_public_id, preview_snapshot_hash: preview.preview_snapshot_hash }
			} ).then( function ( response ) {
				busy = false;
				preview = null;
				showActivity( response && response.activity ? response.activity : null );
			}, function ( error ) {
				busy = false;
				preview = null;
				show( [ problem( errorMessage( error ) ) ] );
				sync();
			} );
		}

		// After sending, the box shows the post's sharing state like the rest of the box does.
		function showActivity( activity ) {
			var items = activity && activity.items ? activity.items : [];
			var wrap = node( 'div', 'pq-ed-activity' );
			if ( items.length ) {
				var list = node( 'ul', 'pq-ed-channels' );
				items.forEach( function ( item ) {
					var row = node( 'li', 'pq-ed-activity-row' );
					var state = node( 'span', 'pq-ed-activity-state' );
					var pill = node( 'span', 'pq-pill pq-pill--' + ( item.tone || 'idle' ), item.label || '' );
					if ( item.help ) {
						pill.title = item.help;
					}
					state.appendChild( pill );
					state.appendChild( document.createTextNode( ' ' ) );
					state.appendChild( node( 'span', 'pq-ed-small pq-ed-muted', item.time || '' ) );
					row.appendChild( channelName( item ) );
					row.appendChild( state );
					list.appendChild( row );
				} );
				wrap.appendChild( list );
			} else {
				wrap.appendChild( node( 'p', 'pq-ed-muted', __( 'Shared. It shows up here in a moment.', 'pressqueue' ) ) );
			}
			var previous = box.querySelector( '.pq-ed-activity' );
			if ( previous ) {
				var link = previous.querySelector( 'a' );
				if ( link && link.parentNode ) {
					wrap.appendChild( link.parentNode );
				}
				previous.parentNode.replaceChild( wrap, previous );
			} else {
				root.parentNode.insertBefore( wrap, root );
			}
			root.parentNode.removeChild( root );
		}

		start.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			if ( busy ) {
				return;
			}
			busy = true;
			forget();
			sync();
			show( [ node( 'p', 'pq-ed-muted', __( 'Checking what would be sent…', 'pressqueue' ) ) ] );
			wp.apiFetch( { path: path + '/preview', method: 'POST', data: {} } ).then( function ( response ) {
				busy = false;
				preview = response && response.share_now ? response.share_now : null;
				if ( preview ) {
					renderPreview( preview );
				} else {
					show( [ problem( errorMessage( null ) ) ] );
				}
				sync();
			}, function ( error ) {
				busy = false;
				show( [ problem( errorMessage( error ) ) ] );
				sync();
			} );
		} );

		if ( toggle ) {
			toggle.addEventListener( 'change', function () {
				if ( toggle.checked !== savedOn && preview ) {
					forget();
					show( [] );
				}
				sync();
			} );
		}

		root.hidden = false;
		sync();
	}

	function setup( box ) {
		setupShareSwitch( box );
		setupDependentFields( box );
		setupCounter( box );
		setupMediaPicker( box );
		setupShareNow( box );
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
