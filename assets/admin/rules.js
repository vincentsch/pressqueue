/**
 * PressQueue Rules screen behavior: message composer, live preview, template
 * picker and friendly checks before saving. The screen works without it.
 */
( function ( wp ) {
	'use strict';

	var __ = wp && wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( text ) {
		return text;
	};
	var sprintf = wp && wp.i18n && wp.i18n.sprintf ? wp.i18n.sprintf : function ( format ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var index = 0;
		return format.replace( /%(\d+\$)?[sd]/g, function ( match, position ) {
			var value = position ? args[ parseInt( position, 10 ) - 1 ] : args[ index++ ];
			return String( value );
		} );
	};

	var KNOWN_TAGS = [ 'title', 'excerpt', 'content', 'canonical_url', 'published_at', 'author' ];

	function ready( callback ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', callback );
		} else {
			callback();
		}
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		return node;
	}

	function chars( text ) {
		return Array.from( text );
	}

	function collapse( text ) {
		return String( text || '' ).replace( /\s+/g, ' ' ).trim();
	}

	function truncate( value, maximum ) {
		var list = chars( value );
		if ( maximum <= 0 || list.length <= maximum ) {
			return value;
		}
		if ( maximum <= 3 ) {
			return '...'.slice( 0, maximum );
		}
		return list.slice( 0, maximum - 3 ).join( '' ) + '...';
	}

	function parseJson( text ) {
		try {
			return JSON.parse( text );
		} catch ( error ) {
			return null;
		}
	}

	/* ---------- Composer ---------- */

	function Composer( root ) {
		this.root = root;
		this.form = root.closest( 'form' );
		this.fields = Array.prototype.slice.call( root.querySelectorAll( '[data-pq-message]' ) );
		this.main = root.querySelector( '[data-pq-message="default"]' );
		this.lastFocused = this.main;
		this.json = this.form ? this.form.querySelector( '[data-pq-template-json]' ) : null;
		this.preview = document.querySelector( '[data-pq-preview]' );
		this.postIndex = 0;
		this.posts = [];
		this.channels = [];
		this.bind();
	}

	Composer.prototype.bind = function () {
		var self = this;

		this.fields.forEach( function ( field ) {
			field.addEventListener( 'focus', function () {
				self.lastFocused = field;
				self.hasCaret = true;
			} );
			field.addEventListener( 'input', function () {
				self.writeJson();
				self.update();
			} );
		} );

		this.root.querySelectorAll( '[data-pq-insert]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				self.insert( button.getAttribute( 'data-pq-insert' ) );
			} );
		} );

		if ( this.json ) {
			this.json.addEventListener( 'input', function () {
				self.readJson();
				self.update();
			} );
		}

		if ( this.preview ) {
			this.posts = parseJson( this.preview.getAttribute( 'data-pq-posts' ) ) || [];
			this.channels = parseJson( this.preview.getAttribute( 'data-pq-channels' ) ) || [];
			this.channelSelect = this.preview.querySelector( '[data-pq-preview-channel]' );
			if ( this.channelSelect ) {
				this.channelSelect.addEventListener( 'change', function () {
					self.update();
				} );
			}
			var next = this.preview.querySelector( '[data-pq-preview-next]' );
			if ( next ) {
				next.hidden = this.posts.length < 2;
				next.addEventListener( 'click', function () {
					self.postIndex = ( self.postIndex + 1 ) % Math.max( 1, self.posts.length );
					self.update();
				} );
			}
		}

		if ( this.form ) {
			this.form.addEventListener( 'change', function ( event ) {
				var target = event.target;
				if ( target.matches( 'input[name="destination_channel_ids[]"]' ) ) {
					self.fillChannels();
					self.update();
				}
				if ( target.matches( '[data-pq-media]' ) || target.matches( '[data-pq-custom-fields]' ) ) {
					self.update();
				}
			} );
		}

		this.fillChannels();
		this.update();
	};

	Composer.prototype.insert = function ( tag ) {
		var field = this.lastFocused || this.main;
		var start = ! this.hasCaret || field.selectionStart === undefined || field.selectionStart === null ? field.value.length : field.selectionStart;
		var end = ! this.hasCaret || field.selectionEnd === undefined || field.selectionEnd === null ? start : field.selectionEnd;
		var before = field.value.slice( 0, start );
		var after = field.value.slice( end );
		var pad = before && ! /\s$/.test( before ) ? ' ' : '';
		var padAfter = after && ! /^\s/.test( after ) ? ' ' : '';
		field.value = before + pad + tag + padAfter + after;
		field.focus();
		var position = ( before + pad + tag ).length;
		field.setSelectionRange( position, position );
		this.writeJson();
		this.update();
	};

	Composer.prototype.serviceMessages = function () {
		var messages = {};
		this.fields.forEach( function ( field ) {
			var service = field.getAttribute( 'data-pq-message' );
			if ( service !== 'default' && field.value.trim() ) {
				messages[ service ] = field.value.trim();
			}
		} );
		return messages;
	};

	Composer.prototype.writeJson = function () {
		if ( ! this.json ) {
			return;
		}
		var current = parseJson( this.json.value );
		if ( ! current || typeof current !== 'object' || Array.isArray( current ) ) {
			return;
		}
		var messages = this.serviceMessages();
		current.default_message = this.main.value.trim();
		current.schema_version = current.schema_version || 1;
		if ( Object.keys( messages ).length ) {
			current.service_messages = messages;
		} else {
			delete current.service_messages;
		}
		this.json.value = JSON.stringify( current, null, 2 );
	};

	Composer.prototype.readJson = function () {
		var current = parseJson( this.json.value );
		if ( ! current || typeof current !== 'object' || typeof current.default_message !== 'string' ) {
			return;
		}
		var messages = current.service_messages && typeof current.service_messages === 'object' ? current.service_messages : {};
		this.main.value = current.default_message;
		this.fields.forEach( function ( field ) {
			var service = field.getAttribute( 'data-pq-message' );
			if ( service !== 'default' ) {
				field.value = typeof messages[ service ] === 'string' ? messages[ service ] : '';
			}
		} );
	};

	Composer.prototype.applyTemplate = function ( set ) {
		var messages = set.service_messages && typeof set.service_messages === 'object' ? set.service_messages : {};
		var hasVariants = false;
		this.main.value = typeof set.default_message === 'string' ? set.default_message : '';
		this.fields.forEach( function ( field ) {
			var service = field.getAttribute( 'data-pq-message' );
			if ( service !== 'default' ) {
				field.value = typeof messages[ service ] === 'string' ? messages[ service ] : '';
				hasVariants = hasVariants || !! field.value;
			}
		} );
		if ( hasVariants ) {
			var details = this.root.querySelector( 'details' );
			if ( details ) {
				details.open = true;
			}
		}
		if ( this.json ) {
			this.json.value = JSON.stringify( set, null, 2 );
		}
		this.update();
	};

	Composer.prototype.fillChannels = function () {
		if ( ! this.channelSelect ) {
			return;
		}
		var checked = this.form ? Array.prototype.slice.call( this.form.querySelectorAll( 'input[name="destination_channel_ids[]"]:checked' ) ).map( function ( input ) {
			return input.value;
		} ) : [];
		var list = this.channels.filter( function ( channel ) {
			return checked.indexOf( channel.id ) !== -1;
		} );
		if ( ! list.length ) {
			list = this.channels.filter( function ( channel ) {
				return channel.enabled;
			} );
		}
		if ( ! list.length ) {
			list = this.channels.slice();
		}
		if ( ! list.length ) {
			list = [ { id: '', service: 'x', label: 'X', monogram: 'X', name: __( 'Your channel', 'pressqueue' ), limit: 280, enabled: true } ];
		}
		var previous = this.channelSelect.value;
		this.channelSelect.innerHTML = '';
		list.forEach( function ( channel ) {
			var option = document.createElement( 'option' );
			option.value = channel.id;
			option.textContent = channel.label + ': ' + channel.name;
			option.pqChannel = channel;
			this.channelSelect.appendChild( option );
		}, this );
		if ( list.some( function ( channel ) {
			return channel.id === previous;
		} ) ) {
			this.channelSelect.value = previous;
		}
	};

	Composer.prototype.currentChannel = function () {
		if ( ! this.channelSelect || this.channelSelect.selectedIndex < 0 ) {
			return { service: 'x', label: 'X', monogram: 'X', name: '', limit: 280 };
		}
		return this.channelSelect.options[ this.channelSelect.selectedIndex ].pqChannel;
	};

	Composer.prototype.customFields = function () {
		var input = this.form ? this.form.querySelector( '[data-pq-custom-fields]' ) : null;
		if ( ! input ) {
			return null;
		}
		return input.value.split( ',' ).map( function ( item ) {
			return item.trim();
		} ).filter( Boolean );
	};

	Composer.prototype.render = function ( template, post ) {
		var problems = [];
		var parts = [];
		var allowed = this.customFields();
		var cleaned = collapse( template );
		var pattern = /\{\{\s*([^}]+?)\s*\}\}/g;
		var last = 0;
		var match;

		while ( ( match = pattern.exec( cleaned ) ) !== null ) {
			parts.push( { text: cleaned.slice( last, match.index ) } );
			parts.push( this.renderTag( match[ 1 ].trim(), post, allowed, problems ) );
			last = pattern.lastIndex;
		}
		parts.push( { text: cleaned.slice( last ) } );

		var text = collapse( parts.map( function ( part ) {
			return part.text;
		} ).join( '' ) );

		if ( /\{\{|\}\}/.test( cleaned.replace( /\{\{\s*[^}]+?\s*\}\}/g, '' ) ) ) {
			problems.push( __( 'A placeholder is missing its {{ or }}.', 'pressqueue' ) );
		}

		return { parts: parts, text: truncate( text, 1000 ), problems: problems };
	};

	Composer.prototype.renderTag = function ( expression, post, allowed, problems ) {
		var pieces = expression.split( '|' ).map( function ( piece ) {
			return piece.trim();
		} );
		var tag = pieces.shift();
		var value = '';
		var known = true;
		var placeholder = false;

		if ( KNOWN_TAGS.indexOf( tag ) !== -1 ) {
			if ( tag === 'canonical_url' ) {
				value = post.url || '';
			} else if ( tag === 'published_at' ) {
				value = post.date || '';
			} else {
				value = post[ tag ] || '';
			}
		} else if ( tag.indexOf( 'terms:' ) === 0 ) {
			var names = post.terms && post.terms[ tag.slice( 6 ) ] ? post.terms[ tag.slice( 6 ) ] : [];
			value = names.join( ', ' );
		} else if ( tag.indexOf( 'meta:' ) === 0 ) {
			var key = tag.slice( 5 );
			placeholder = true;
			value = '[' + key + ']';
			if ( allowed !== null && allowed.indexOf( key ) === -1 ) {
				problems.push( sprintf( /* translators: %s: custom field name. */ __( 'Add "%s" to the custom fields under Advanced, or the rule cannot be saved.', 'pressqueue' ), key ) );
			}
		} else {
			known = false;
			problems.push( sprintf( /* translators: %s: placeholder name. */ __( 'PressQueue does not know {{ %s }}. Posts would be stopped until you fix it.', 'pressqueue' ), tag ) );
		}

		pieces.forEach( function ( modifier ) {
			var found = /^truncate:([1-9][0-9]*)$/.exec( modifier );
			if ( ! found ) {
				known = false;
				problems.push( sprintf( /* translators: %s: modifier text. */ __( 'The modifier "%s" is not supported. Use truncate:120, for example.', 'pressqueue' ), modifier ) );
				return;
			}
			if ( ! placeholder ) {
				value = truncate( collapse( value ), parseInt( found[ 1 ], 10 ) );
			}
		} );

		if ( ! known ) {
			return { text: '{{ ' + expression + ' }}', unknown: true };
		}
		return { text: collapse( value ), placeholder: placeholder };
	};

	Composer.prototype.update = function () {
		if ( ! this.preview || ! this.main ) {
			return;
		}

		var post = this.posts[ this.postIndex ] || { title: '', url: '', terms: {} };
		var channel = this.currentChannel();
		var variant = this.root.querySelector( '[data-pq-message="' + channel.service + '"]' );
		var template = variant && variant.value.trim() ? variant.value : this.main.value;
		var result = this.render( template, post );
		var url = post.url || '';
		var count = chars( result.text ).length + 2 + chars( url ).length;
		var limit = parseInt( channel.limit, 10 ) || 280;

		var badge = this.preview.querySelector( '[data-pq-preview-badge]' );
		badge.innerHTML = '';
		var svc = el( 'span', 'pq-svc pq-svc--' + String( channel.service ).replace( /[^a-z0-9_-]/gi, '' ), channel.monogram || '' );
		svc.setAttribute( 'aria-hidden', 'true' );
		badge.appendChild( svc );
		this.preview.querySelector( '[data-pq-preview-name]' ).textContent = channel.name || channel.label;

		var out = this.preview.querySelector( '[data-pq-preview-text]' );
		out.innerHTML = '';
		result.parts.forEach( function ( part ) {
			if ( part.unknown || part.placeholder ) {
				out.appendChild( el( 'span', 'pq-preview-unknown', part.text ) );
			} else {
				out.appendChild( document.createTextNode( part.text ) );
			}
		} );
		out.normalize();
		if ( out.firstChild && out.firstChild.nodeType === 3 ) {
			out.firstChild.textContent = out.firstChild.textContent.replace( /^\s+/, '' );
		}
		if ( out.lastChild && out.lastChild.nodeType === 3 ) {
			out.lastChild.textContent = out.lastChild.textContent.replace( /\s+$/, '' );
		}
		out.appendChild( document.createTextNode( '\n\n' ) );
		out.appendChild( el( 'span', 'pq-preview-link', url.replace( /^https?:\/\//, '' ) ) );

		var warning = this.preview.querySelector( '[data-pq-preview-warning]' );
		var problems = result.problems.slice();
		var imageProblem = this.renderImage( post );
		if ( ! collapse( template ) ) {
			problems.unshift( __( 'Write a message. The link alone is not enough.', 'pressqueue' ) );
		}
		if ( imageProblem ) {
			problems.push( imageProblem );
		}
		warning.textContent = problems.join( ' ' );
		warning.hidden = ! problems.length;

		var meter = this.preview.querySelector( '[data-pq-meter]' );
		var bar = this.preview.querySelector( '[data-pq-meter-bar]' );
		var label = this.preview.querySelector( '[data-pq-meter-text]' );
		meter.classList.toggle( 'is-bad', count > limit );
		meter.classList.toggle( 'is-warn', count <= limit && count > limit * 0.9 );
		bar.style.width = Math.min( 100, ( count / limit ) * 100 ) + '%';
		label.textContent = count > limit
			? sprintf( /* translators: %d: number of characters. */ __( '%d characters too long. Shorten the message.', 'pressqueue' ), count - limit )
			: sprintf( /* translators: %d: number of characters. */ __( '%d characters used, including the link', 'pressqueue' ), count );
		this.preview.querySelector( '[data-pq-meter-limit]' ).textContent = sprintf( /* translators: %d: character limit. */ __( 'Limit %d', 'pressqueue' ), limit );

		var title = this.preview.querySelector( '[data-pq-preview-post]' );
		if ( title ) {
			title.textContent = post.title ? '"' + post.title + '"' : '';
		}
	};

	Composer.prototype.renderImage = function ( post ) {
		var box = this.preview.querySelector( '[data-pq-preview-image]' );
		var select = this.form ? this.form.querySelector( '[data-pq-media]' ) : null;
		var mode = select ? select.value : 'none';
		box.style.backgroundImage = '';
		box.textContent = '';
		box.hidden = true;

		if ( mode === 'post_media' ) {
			box.hidden = false;
			box.textContent = __( 'Images chosen in the post', 'pressqueue' );
			return '';
		}

		if ( mode === 'social_card' && post.card ) {
			box.hidden = false;
			box.style.backgroundImage = 'url("' + String( post.card ).replace( /["\\]/g, '' ) + '")';
			return '';
		}

		if ( ( mode === 'featured_image_optional' || mode === 'featured_image' ) && post.image ) {
			box.hidden = false;
			box.style.backgroundImage = 'url("' + String( post.image ).replace( /["\\]/g, '' ) + '")';
			box.textContent = __( 'Featured image', 'pressqueue' );
		}

		if ( mode === 'featured_image' && ! post.image ) {
			return __( 'This post has no featured image, so this rule would hold it back.', 'pressqueue' );
		}

		return '';
	};

	/* ---------- Template picker ---------- */

	function initTemplatePicker( composer ) {
		var picker = document.querySelector( '.pq-template-picker' );
		if ( ! picker || ! composer ) {
			return;
		}
		var select = picker.querySelector( '[data-pq-template-pick]' );
		var button = picker.querySelector( '[data-pq-template-use]' );
		var adopted = picker.querySelector( '[data-pq-adopted]' );
		var status = picker.querySelector( '[data-pq-template-status]' );

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			var option = select.options[ select.selectedIndex ];
			if ( ! option || ! option.value ) {
				status.textContent = __( 'Choose a template first.', 'pressqueue' );
				select.focus();
				return;
			}
			var set = parseJson( option.getAttribute( 'data-set' ) || '' );
			if ( ! set ) {
				return;
			}
			composer.applyTemplate( set );
			adopted.value = option.value;
			status.textContent = sprintf(
				/* translators: %s: template name. */
				__( 'Copied "%s" into the message. Save the rule to keep it.', 'pressqueue' ),
				option.textContent
			);
			composer.main.focus();
		} );
	}

	/* ---------- Image help ---------- */

	function initMediaHelp() {
		var select = document.querySelector( '[data-pq-media]' );
		var help = document.querySelector( '[data-pq-media-help]' );
		if ( ! select || ! help ) {
			return;
		}
		select.addEventListener( 'change', function () {
			var option = select.options[ select.selectedIndex ];
			help.textContent = option ? option.getAttribute( 'data-help' ) || '' : '';
		} );
	}

	/* ---------- Checks before saving ---------- */

	function showError( section, message ) {
		var host = document.getElementById( 'pq-section-' + section );
		if ( ! host ) {
			return;
		}
		var error = el( 'p', 'pq-field-error', message );
		error.setAttribute( 'role', 'alert' );
		error.setAttribute( 'data-pq-client-error', '' );
		var heading = host.querySelector( 'h2' );
		if ( heading && heading.parentNode === host ) {
			heading.insertAdjacentElement( 'afterend', error );
		} else {
			host.insertBefore( error, host.firstChild );
		}
	}

	function initValidation( form ) {
		if ( ! form || ! form.matches( '#pq-rule-form' ) ) {
			return;
		}
		var dirty = false;

		form.addEventListener( 'input', function () {
			dirty = true;
		} );
		form.addEventListener( 'change', function () {
			dirty = true;
		} );
		window.addEventListener( 'beforeunload', function ( event ) {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			var problems = [];
			form.querySelectorAll( '[data-pq-client-error]' ).forEach( function ( node ) {
				node.remove();
			} );

			if ( form.querySelector( '[name="when_controls_present"]' ) && ! form.querySelector( 'input[name="when[]"]:checked' ) ) {
				problems.push( [ 'when', __( 'Choose at least one moment that starts a share.', 'pressqueue' ), form.querySelector( 'input[name="when[]"]' ) ] );
			}

			var builder = form.querySelector( '.pressqueue-condition-builder' );
			if ( builder && builder.pqBuilder ) {
				var result = builder.pqBuilder.validate();
				if ( result ) {
					problems.push( [ 'conditions', result.message, result.element ] );
				}
			}

			var channels = form.querySelectorAll( 'input[name="destination_channel_ids[]"]' );
			if ( form.querySelector( '[name="destination_controls_present"]' ) && 'include' === form.getAttribute( 'data-pq-effect' ) && ! form.querySelector( 'input[name="destination_channel_ids[]"]:checked' ) ) {
				problems.push( [ 'where', channels.length ? __( 'Pick at least one channel.', 'pressqueue' ) : __( 'Switch on a channel in Channels first.', 'pressqueue' ), channels[ 0 ] || null ] );
			}

			var message = form.querySelector( '[data-pq-message="default"]' );
			if ( message && ! message.value.trim() ) {
				problems.push( [ 'message', __( 'Write a message. The link to the post is added after it.', 'pressqueue' ), message ] );
			}

			if ( ! problems.length ) {
				dirty = false;
				return;
			}

			event.preventDefault();
			event.stopPropagation();
			problems.forEach( function ( problem ) {
				showError( problem[ 0 ], problem[ 1 ] );
			} );
			var first = problems[ 0 ][ 2 ] || document.getElementById( 'pq-section-' + problems[ 0 ][ 0 ] );
			if ( first ) {
				first.scrollIntoView( { block: 'center', behavior: 'smooth' } );
				if ( first.focus ) {
					first.focus( { preventScroll: true } );
				}
			}
		} );
	}

	function revealServerError() {
		var error = document.querySelector( '.pq-rule-form .pq-field-error' );
		if ( error ) {
			var details = error.closest( 'details' );
			if ( details ) {
				details.open = true;
			}
			error.scrollIntoView( { block: 'center' } );
		}
	}

	ready( function () {
		var root = document.querySelector( '[data-pq-composer]' );
		var composer = root ? new Composer( root ) : null;
		initTemplatePicker( composer );
		initMediaHelp();
		initValidation( document.getElementById( 'pq-rule-form' ) );
		revealServerError();
	} );
}( window.wp || {} ) );
