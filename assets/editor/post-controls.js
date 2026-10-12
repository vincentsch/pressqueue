( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.editor || ! wp.element || ! wp.components || ! wp.apiFetch || ! wp.data || ! wp.i18n || ! wp.hooks ) {
		return;
	}

	config = config || {};

	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useEffect = wp.element.useEffect;
	var useState = wp.element.useState;
	var useRef = wp.element.useRef;
	var useSelect = wp.data.useSelect;
	var Panel = wp.editor.PluginDocumentSettingPanel;
	var PrePublishPanel = wp.editor.PluginPrePublishPanel || null;
	var Button = wp.components.Button;
	var Notice = wp.components.Notice;
	var SelectControl = wp.components.SelectControl;
	var TextareaControl = wp.components.TextareaControl;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var Spinner = wp.components.Spinner;
	var MediaUpload = wp.blockEditor && wp.blockEditor.MediaUpload ? wp.blockEditor.MediaUpload : null;

	// A transient edit that marks the post dirty while sharing settings are unsaved,
	// so Save and Update stay available. It is never sent to the server.
	var MARKER = 'pressqueue_sharing_changed';
	// WordPress 6.7 added async editor filters together with editor.preSavePost.
	var SAVE_WITH_POST = typeof wp.hooks.applyFiltersAsync === 'function';
	var PREVIEW_DELAY = 700;
	var ACTIVITY_REFRESH_DELAY = 4000;
	var PENDING_STATES = [ 'proposed', 'queued', 'leased', 'retrying' ];

	// Shared between the panel and the editor save hooks.
	var saver = { current: null };
	var savedListener = { current: null };

	function inheritedBundle() {
		return {
			schema_version: 2,
			policy: 'inherit',
			channels: { mode: 'inherit' },
			message: { mode: 'inherit' },
			schedule: { mode: 'inherit' },
			media: { mode: 'inherit' }
		};
	}

	function normalizeControls( controls ) {
		var base = inheritedBundle();
		if ( ! controls || typeof controls !== 'object' ) {
			return base;
		}
		return {
			schema_version: 2,
			policy: typeof controls.policy === 'string' ? controls.policy : 'inherit',
			channels: controls.channels && controls.channels.mode ? controls.channels : base.channels,
			message: controls.message && controls.message.mode ? controls.message : base.message,
			schedule: controls.schedule && controls.schedule.mode ? controls.schedule : base.schedule,
			media: controls.media && controls.media.mode ? controls.media : base.media
		};
	}

	function canonical( value ) {
		if ( Array.isArray( value ) ) {
			return '[' + value.map( canonical ).join( ',' ) + ']';
		}
		if ( value && typeof value === 'object' ) {
			return '{' + Object.keys( value ).sort().map( function ( key ) {
				return JSON.stringify( key ) + ':' + canonical( value[ key ] );
			} ).join( ',' ) + '}';
		}
		return JSON.stringify( value === undefined ? null : value );
	}

	function isOn( controls ) {
		return controls.policy !== 'exclude';
	}

	function hasCustomizations( controls ) {
		return [ 'channels', 'message', 'schedule', 'media' ].some( function ( key ) {
			return controls[ key ] && controls[ key ].mode !== 'inherit';
		} );
	}

	function withControl( controls, key, value ) {
		var next = Object.assign( {}, controls );
		next[ key ] = value;
		return next;
	}

	function withoutMarker( edits ) {
		if ( ! edits || ! Object.prototype.hasOwnProperty.call( edits, MARKER ) ) {
			return edits;
		}
		var copy = Object.assign( {}, edits );
		delete copy[ MARKER ];
		return copy;
	}

	function charLength( text ) {
		return Array.from( text ).length;
	}

	function plainText( value ) {
		return String( value || '' ).replace( /\s+/g, ' ' ).trim();
	}

	function errorCode( error ) {
		return error && typeof error.code === 'string' ? error.code : '';
	}

	function errorStatus( error ) {
		return error && error.data && error.data.status ? error.data.status : 0;
	}

	function saveErrorMessage( error ) {
		var code = errorCode( error );
		if ( code === 'conflict' ) {
			return __( 'Sharing settings for this post were changed in another window. Load the latest settings in the Social sharing panel, then save again.', 'pressqueue' );
		}
		if ( code === 'invalid_controls' && error.message ) {
			/* translators: %s: reason from the server. */
			return sprintf( __( 'Sharing settings could not be saved: %s', 'pressqueue' ), error.message );
		}
		if ( code === 'forbidden' || code === 'unauthenticated' ) {
			return __( 'Sharing settings could not be saved because your account cannot change them anymore. Reload the page.', 'pressqueue' );
		}
		return __( 'Sharing settings could not be saved. Check your connection and try again.', 'pressqueue' );
	}

	// Checks that need no server round trip.
	function validationProblem( controls ) {
		if ( ! isOn( controls ) ) {
			return '';
		}
		var schedule = controls.schedule || {};
		if ( schedule.mode === 'explicit_time' && ( ! schedule.local_date || ! schedule.local_time || ! schedule.timezone ) ) {
			return 'schedule';
		}
		var media = controls.media || {};
		if ( media.mode === 'post_media' && ! ( media.post_media && media.post_media.attachment_ids && media.post_media.attachment_ids.length ) ) {
			return 'media';
		}
		return '';
	}

	function unfinishedSchedule( controls ) {
		var schedule = controls.schedule || {};
		return schedule.mode === 'explicit_time' && ( ! schedule.local_date || ! schedule.local_time );
	}

	// What is stored. A half-filled date and time only matters while sharing is on,
	// so it is dropped instead of blocking the save when sharing is off.
	function savableControls( controls ) {
		if ( isOn( controls ) || ! unfinishedSchedule( controls ) ) {
			return controls;
		}
		return withControl( controls, 'schedule', { mode: 'inherit' } );
	}

	// What the preview checks. With sharing off it still checks the post as if sharing
	// were on, so a matching "never share" rule can be shown.
	function previewControls( controls ) {
		if ( isOn( controls ) ) {
			return controls;
		}
		return withControl( savableControls( controls ), 'policy', 'inherit' );
	}

	// Shown when a save stops, so it says where to fix the problem.
	function validationError( controls ) {
		var problem = validationProblem( controls );
		if ( problem === 'schedule' ) {
			return __( 'Pick a date and a time for sharing, or choose another timing in the Social sharing panel.', 'pressqueue' );
		}
		if ( problem === 'media' ) {
			return __( 'Choose at least one image for sharing, or pick another image setting in the Social sharing panel.', 'pressqueue' );
		}
		return '';
	}

	// Shown inside the panel instead of the summary.
	function problemHint( problem ) {
		if ( problem === 'schedule' ) {
			return __( 'Pick a date and a time below to see where this post goes.', 'pressqueue' );
		}
		return __( 'Choose at least one image below to see where this post goes.', 'pressqueue' );
	}

	if ( SAVE_WITH_POST ) {
		// Runs before WordPress sends the post. Throwing here aborts the post save,
		// so a post is never saved or published with unsaved sharing settings.
		wp.hooks.addFilter( 'editor.preSavePost', 'pressqueue/post-controls', function ( edits, options ) {
			var clean = withoutMarker( edits );
			if ( ( options && options.isAutosave ) || typeof saver.current !== 'function' ) {
				return clean;
			}
			return Promise.resolve( saver.current( options || {} ) ).then( function () {
				return clean;
			} );
		} );
		wp.hooks.addAction( 'editor.savePost', 'pressqueue/post-controls', function ( post, options ) {
			if ( ( options && options.isAutosave ) || typeof savedListener.current !== 'function' ) {
				return;
			}
			savedListener.current();
		} );
	}

	function setMarker( dirty ) {
		var select = wp.data.select( 'core/editor' );
		var edits = select && select.getPostEdits ? select.getPostEdits() : {};
		var present = !! ( edits && edits[ MARKER ] );
		if ( present === !! dirty ) {
			return;
		}
		var edit = {};
		edit[ MARKER ] = dirty ? true : undefined;
		wp.data.dispatch( 'core/editor' ).editPost( edit, { undoIgnore: true } );
	}

	function initialState() {
		return {
			loaded: false,
			loadError: '',
			unavailable: false,
			controls: inheritedBundle(),
			saved: inheritedBundle(),
			hash: '',
			editor: {},
			facts: {},
			saving: false,
			saveError: '',
			conflict: false,
			activity: null
		};
	}

	function useSharingState( postId, enabled ) {
		var pair = useState( initialState );
		var data = pair[ 0 ];
		var setData = pair[ 1 ];
		var generation = useRef( 0 );
		var latest = useRef( {} );
		var queue = useRef( Promise.resolve() );
		var activityTimer = useRef( null );
		var tickPair = useState( 0 );
		var activityTick = tickPair[ 0 ];
		var setActivityTick = tickPair[ 1 ];
		var dirty = data.loaded && canonical( data.controls ) !== canonical( data.saved );

		latest.current = {
			postId: postId,
			loaded: data.loaded,
			dirty: dirty,
			controls: data.controls,
			hash: data.hash
		};

		function update( changes ) {
			setData( function ( current ) {
				return Object.assign( {}, current, typeof changes === 'function' ? changes( current ) : changes );
			} );
		}

		function load( keepNotice ) {
			var requestGeneration = ++generation.current;
			if ( ! postId || ! enabled ) {
				setData( initialState() );
				return;
			}
			if ( ! keepNotice ) {
				setData( initialState() );
			}
			wp.apiFetch( { path: '/pressqueue/v1/posts/' + postId + '/controls' } )
				.then( function ( response ) {
					if ( requestGeneration !== generation.current ) {
						return;
					}
					var controls = normalizeControls( response.controls );
					setData( Object.assign( initialState(), {
						loaded: true,
						controls: controls,
						saved: controls,
						hash: response.control_bundle_hash,
						editor: response.editor || {},
						facts: response.effective_facts || {}
					} ) );
				} )
				.catch( function ( error ) {
					if ( requestGeneration !== generation.current ) {
						return;
					}
					var status = errorStatus( error );
					var code = errorCode( error );
					if ( status === 401 || status === 403 || status === 404 || code === 'forbidden' || code === 'invalid_post_type' ) {
						setData( Object.assign( initialState(), { unavailable: true } ) );
						return;
					}
					setData( Object.assign( initialState(), {
						loadError: __( 'Sharing settings could not be loaded. Check your connection and try again.', 'pressqueue' )
					} ) );
				} );
		}

		useEffect( function () {
			load( false );
			return function () {
				generation.current++;
			};
		}, [ postId, enabled ] );

		// Keep the post dirty while sharing settings are unsaved.
		useEffect( function () {
			if ( ! data.loaded ) {
				return undefined;
			}
			if ( SAVE_WITH_POST ) {
				setMarker( dirty );
				return undefined;
			}
			if ( ! dirty ) {
				return undefined;
			}
			// Older editors without async save filters: block saving until the settings are stored.
			var editor = wp.data.dispatch( 'core/editor' );
			editor.lockPostSaving( 'pressqueue-controls' );
			return function () {
				editor.unlockPostSaving( 'pressqueue-controls' );
			};
		}, [ dirty, data.loaded ] );

		function persist() {
			var current = latest.current;
			if ( ! current.loaded || ! current.dirty ) {
				if ( SAVE_WITH_POST ) {
					setMarker( false );
				}
				return Promise.resolve();
			}

			var invalid = validationError( current.controls );
			if ( invalid ) {
				update( { saveError: invalid, conflict: false } );
				return Promise.reject( new Error( invalid ) );
			}

			var requestPostId = current.postId;
			update( { saving: true, saveError: '', conflict: false } );
			return wp.apiFetch( {
				path: '/pressqueue/v1/posts/' + requestPostId + '/controls',
				method: 'PUT',
				data: {
					base_control_bundle_hash: current.hash,
					controls: savableControls( current.controls )
				}
			} ).then( function ( response ) {
				var controls = normalizeControls( response.controls );
				latest.current = Object.assign( {}, latest.current, {
					controls: controls,
					dirty: false,
					hash: response.control_bundle_hash
				} );
				update( function ( state ) {
					return {
						controls: controls,
						saved: controls,
						hash: response.control_bundle_hash,
						editor: response.editor || state.editor,
						facts: response.effective_facts || state.facts,
						saving: false,
						saveError: '',
						conflict: false
					};
				} );
				if ( SAVE_WITH_POST ) {
					setMarker( false );
				}
			}, function ( error ) {
				var message = saveErrorMessage( error );
				update( {
					saving: false,
					saveError: message,
					conflict: errorCode( error ) === 'conflict'
				} );
				throw new Error( message );
			} );
		}

		function save() {
			var run = queue.current.then( persist, persist );
			queue.current = run.catch( function () {} );
			return run;
		}

		useEffect( function () {
			if ( ! enabled ) {
				return undefined;
			}
			saver.current = save;
			savedListener.current = function () {
				setActivityTick( function ( value ) {
					return value + 1;
				} );
			};
			return function () {
				if ( saver.current === save ) {
					saver.current = null;
				}
				savedListener.current = null;
			};
		} );

		function loadActivity( isFollowUp ) {
			var requestPostId = postId;
			wp.apiFetch( { path: '/pressqueue/v1/posts/' + requestPostId + '/activity' } )
				.then( function ( response ) {
					if ( latest.current.postId !== requestPostId ) {
						return;
					}
					var activity = response && response.activity ? response.activity : null;
					update( { activity: activity } );
					var items = activity && activity.items ? activity.items : [];
					var pending = items.length === 0 || items.some( function ( item ) {
						return PENDING_STATES.indexOf( item.state ) !== -1;
					} );
					if ( pending && ! isFollowUp ) {
						clearTimeout( activityTimer.current );
						activityTimer.current = setTimeout( function () {
							loadActivity( true );
						}, ACTIVITY_REFRESH_DELAY );
					}
				} )
				.catch( function () {} );
		}

		return {
			data: data,
			dirty: dirty,
			activityTick: activityTick,
			loadActivity: loadActivity,
			stopActivity: function () {
				clearTimeout( activityTimer.current );
			},
			reload: function () {
				load( true );
			},
			save: save,
			setControls: function ( controls ) {
				if ( latest.current.loaded ) {
					update( { controls: normalizeControls( controls ), saveError: '', conflict: false } );
				}
			}
		};
	}

	function editorTerms( select, taxonomies ) {
		var editor = select( 'core/editor' );
		var terms = [];
		function add( taxonomy, ids ) {
			( Array.isArray( ids ) ? ids : [] ).forEach( function ( termId ) {
				terms.push( { taxonomy: taxonomy, term_id: parseInt( termId, 10 ) } );
			} );
		}
		add( 'category', editor.getEditedPostAttribute( 'categories' ) );
		add( 'post_tag', editor.getEditedPostAttribute( 'tags' ) );
		( taxonomies || [] ).forEach( function ( taxonomy ) {
			add( taxonomy.taxonomy, editor.getEditedPostAttribute( taxonomy.rest_base || taxonomy.taxonomy ) );
		} );
		var seen = {};
		return terms.filter( function ( term ) {
			var key = term.taxonomy + ':' + term.term_id;
			if ( seen[ key ] || ! ( term.term_id > 0 ) ) {
				return false;
			}
			seen[ key ] = true;
			return true;
		} );
	}

	function editorMetadata( meta, keys ) {
		// The legacy policy key keeps this an object even when no rule reads custom fields.
		var output = { _pressqueue_policy: null };
		( keys || [] ).forEach( function ( key ) {
			output[ key ] = meta && Object.prototype.hasOwnProperty.call( meta, key ) ? meta[ key ] : null;
		} );
		return output;
	}

	function normalizeDate( value ) {
		if ( ! value ) {
			return '0000-00-00 00:00:00';
		}
		return String( value ).replace( 'T', ' ' ).replace( 'Z', '' ).slice( 0, 19 );
	}

	function rawText( value ) {
		if ( value && typeof value === 'object' ) {
			return String( value.raw || '' );
		}
		return String( value || '' );
	}

	function gmtString( date ) {
		return date.toISOString().slice( 0, 19 ).replace( 'T', ' ' );
	}

	// The status the post has when PressQueue looks at it: published, scheduled or updated.
	function previewStatus( editor ) {
		var status = editor.getEditedPostAttribute( 'status' ) || 'draft';
		if ( status === 'private' ) {
			return 'private';
		}
		if ( editor.getCurrentPostAttribute( 'status' ) === 'publish' ) {
			return 'publish';
		}
		if ( editor.isEditedPostBeingScheduled && editor.isEditedPostBeingScheduled() ) {
			return 'future';
		}
		return 'publish';
	}

	// The publication date in UTC. A draft without a fixed date is published now.
	function publishedAtGmt( editor ) {
		var date = editor.getEditedPostAttribute( 'date' );
		var floating = editor.isEditedPostDateFloating ? editor.isEditedPostDateFloating() : false;
		if ( ! date || floating ) {
			return gmtString( new Date() );
		}
		if ( wp.date && typeof wp.date.getDate === 'function' ) {
			var parsed = wp.date.getDate( date );
			if ( parsed && ! isNaN( parsed.getTime() ) ) {
				return gmtString( parsed );
			}
		}
		return normalizeDate( editor.getEditedPostAttribute( 'date_gmt' ) );
	}

	function editorSnapshot( select, facts ) {
		var editor = select( 'core/editor' );
		return {
			post_type: editor.getCurrentPostType() || 'post',
			post_status: previewStatus( editor ),
			author_id: parseInt( editor.getEditedPostAttribute( 'author' ), 10 ) || 0,
			canonical_url: editor.getPermalink ? editor.getPermalink() || '' : '',
			published_at_gmt: publishedAtGmt( editor ),
			modified_at_gmt: normalizeDate( editor.getEditedPostAttribute( 'modified_gmt' ) ),
			title: rawText( editor.getEditedPostAttribute( 'title' ) ),
			excerpt: rawText( editor.getEditedPostAttribute( 'excerpt' ) ),
			content: editor.getEditedPostContent() || '',
			terms: editorTerms( select, facts.rule_relevant_taxonomies ),
			metadata: editorMetadata( editor.getEditedPostAttribute( 'meta' ) || {}, facts.rule_relevant_metadata_keys )
		};
	}

	// Cheap values that change whenever the preview could change.
	function usePostFacts( facts ) {
		return useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var type = editor.getCurrentPostType();
			var id = editor.getCurrentPostId();
			var record = select( 'core' ) && select( 'core' ).getEditedEntityRecord ? select( 'core' ).getEditedEntityRecord( 'postType', type, id ) : null;
			var taxonomies = ( facts.rule_relevant_taxonomies || [] ).map( function ( taxonomy ) {
				return taxonomy.rest_base || taxonomy.taxonomy;
			} );
			var meta = editor.getEditedPostAttribute( 'meta' ) || {};
			return {
				savedStatus: editor.getCurrentPostAttribute( 'status' ) || '',
				status: editor.getEditedPostAttribute( 'status' ) || '',
				scheduled: editor.isEditedPostBeingScheduled ? !! editor.isEditedPostBeingScheduled() : false,
				password: editor.getEditedPostAttribute( 'password' ) || '',
				author: String( editor.getEditedPostAttribute( 'author' ) || '' ),
				coreSaving: !! ( editor.isSavingPost() && ! editor.isAutosavingPost() ),
				title: rawText( editor.getEditedPostAttribute( 'title' ) ),
				excerpt: rawText( editor.getEditedPostAttribute( 'excerpt' ) ),
				date: String( editor.getEditedPostAttribute( 'date' ) || '' ),
				terms: [ 'categories', 'tags' ].concat( taxonomies ).map( function ( key ) {
					var value = editor.getEditedPostAttribute( key );
					return key + ':' + ( Array.isArray( value ) ? value.join( ',' ) : '' );
				} ).join( '|' ),
				meta: ( facts.rule_relevant_metadata_keys || [] ).map( function ( key ) {
					return JSON.stringify( meta[ key ] === undefined ? null : meta[ key ] );
				} ).join( '|' ),
				content: record ? record.blocks || record.content || null : null
			};
		}, [ facts ] );
	}

	function usePreview( sharing, post, postId ) {
		var data = sharing.data;
		var facts = data.facts || {};
		var pair = useState( { display: null, loading: false, error: '' } );
		var preview = pair[ 0 ];
		var setPreview = pair[ 1 ];
		var sequence = useRef( 0 );
		var checked = previewControls( data.controls );
		var key = canonical( checked );
		var problem = validationProblem( data.controls );
		var active = data.loaded && ! post.password && post.status !== 'private' && ! problem;

		useEffect( function () {
			// Any change to the inputs invalidates the last answer at once, so a stale
			// "never share" lock can never outlive the post facts it was computed for.
			var requestSequence = ++sequence.current;
			if ( ! active ) {
				return undefined;
			}
			setPreview( function ( current ) {
				return Object.assign( {}, current, { loading: true } );
			} );
			var timer = setTimeout( function () {
				var snapshot = editorSnapshot( wp.data.select, facts );
				wp.apiFetch( {
					path: '/pressqueue/v1/posts/' + postId + '/preview',
					method: 'POST',
					data: {
						controls: checked,
						editor_snapshot: snapshot
					}
				} ).then( function ( response ) {
					if ( requestSequence === sequence.current ) {
						setPreview( { display: response && response.display ? response.display : null, loading: false, error: '', sequence: requestSequence } );
					}
				}, function ( error ) {
					if ( requestSequence === sequence.current ) {
						setPreview( function ( current ) {
							return {
								display: current.display,
								sequence: requestSequence,
								loading: false,
								error: error && error.message
									? sprintf(
										/* translators: %s: reason from the server. */
										__( 'PressQueue could not check where this post goes: %s', 'pressqueue' ),
										error.message
									)
									: __( 'PressQueue could not check where this post goes. Check your connection.', 'pressqueue' )
							};
						} );
					}
				} );
			}, PREVIEW_DELAY );
			return function () {
				clearTimeout( timer );
			};
		}, [ active, key, postId, post.status, post.savedStatus, post.scheduled, post.author, post.title, post.excerpt, post.date, post.terms, post.meta, post.content ] );

		useEffect( function () {
			return function () {
				sequence.current++;
			};
		}, [] );

		// Only a successful answer for the current inputs may lock the switch.
		var current = active && ! preview.loading && ! preview.error && preview.sequence === sequence.current;
		var keptOff = current && preview.display && typeof preview.display.kept_off === 'string' ? preview.display.kept_off : '';
		return Object.assign( {}, preview, { problem: problem, keptOff: keptOff } );
	}

	function ChannelName( props ) {
		var channel = props.channel;
		return el(
			'span',
			{ className: 'pq-channel-name' },
			el( 'span', { className: 'pq-svc pq-svc--sm pq-svc--' + String( channel.service || '' ).replace( /[^a-z0-9-]/g, '' ), 'aria-hidden': 'true' }, channel.monogram || '' ),
			el( 'span', { className: 'pq-truncate' }, channel.name || channel.service_name || '' ),
			el( 'span', { className: 'screen-reader-text' }, ' (' + ( channel.service_name || '' ) + ')' )
		);
	}

	function Pill( props ) {
		return el( 'span', { className: 'pq-pill pq-pill--' + ( props.tone || 'idle' ), title: props.title || undefined }, props.label );
	}

	function Summary( props ) {
		var preview = props.preview;
		var display = preview.display;
		var published = props.published;

		if ( props.password ) {
			return el( 'p', { className: 'pq-ed-muted' }, __( 'Password-protected posts are not shared.', 'pressqueue' ) );
		}

		if ( props.isPrivate ) {
			return el( 'p', { className: 'pq-ed-muted' }, __( 'Private posts are not shared.', 'pressqueue' ) );
		}

		if ( preview.problem ) {
			return el( 'p', { className: 'pq-ed-muted' }, problemHint( preview.problem ) );
		}

		if ( preview.error ) {
			return el( 'p', { className: 'pq-ed-muted' }, preview.error );
		}

		// A "not shared" answer for older post facts must not linger as an assurance while re-checking.
		if ( ! display || ( preview.loading && ! display.will_share ) ) {
			return el( 'p', { className: 'pq-ed-muted pq-ed-checking' }, Spinner ? el( Spinner ) : null, __( 'Checking where this post goes…', 'pressqueue' ) );
		}

		if ( ! display.will_share ) {
			if ( published ) {
				return el( 'p', { className: 'pq-ed-muted' }, __( 'Updating this post does not share it again.', 'pressqueue' ) );
			}
			return el(
				'div',
				{ className: 'pq-ed-goes pq-ed-goes--none', 'aria-live': 'polite' },
				el( 'p', null, el( 'strong', null, __( 'Not shared when you publish.', 'pressqueue' ) ), ' ', display.reason )
			);
		}

		return el(
			'div',
			{ className: 'pq-ed-goes' + ( preview.loading ? ' is-updating' : '' ), 'aria-live': 'polite' },
			el( 'p', { className: 'pq-ed-small pq-ed-muted' }, display.heading ),
			el(
				'ul',
				{ className: 'pq-ed-channels' },
				( display.channels || [] ).map( function ( channel ) {
					return el(
						'li',
						{ key: channel.public_id },
						el( ChannelName, { channel: channel } ),
						display.details ? null : el( 'span', { className: 'pq-ed-small pq-ed-muted pq-ed-channel-detail' }, [ channel.timing, channel.image ].filter( Boolean ).join( ' · ' ) )
					);
				} )
			),
			display.details ? el( 'p', { className: 'pq-ed-small pq-ed-muted' }, display.details ) : null,
			( display.left_out || [] ).map( function ( channel ) {
				return el( 'p', { key: 'out-' + channel.public_id, className: 'pq-ed-small pq-ed-muted pq-ed-left-out' }, sprintf(
					/* translators: 1: channel name, 2: reason. */
					__( 'Not sent to %1$s: %2$s', 'pressqueue' ),
					channel.name,
					channel.reason
				) );
			} )
		);
	}

	function Activity( props ) {
		var activity = props.activity;
		if ( ! activity ) {
			return null;
		}
		var items = activity.items || [];
		var body;
		if ( items.length ) {
			body = el(
				'ul',
				{ className: 'pq-ed-channels' },
				items.map( function ( item ) {
					return el(
						'li',
						{ key: item.public_id, className: 'pq-ed-activity-row' },
						el( ChannelName, { channel: item } ),
						el(
							'span',
							{ className: 'pq-ed-activity-state' },
							el( Pill, { tone: item.tone, label: item.label, title: item.help } ),
							' ',
							el( 'span', { className: 'pq-ed-small pq-ed-muted', title: item.time_full || undefined }, item.time )
						),
						item.reason ? el( 'span', { className: 'pq-ed-small pq-ed-activity-reason' }, item.reason ) : null
					);
				} )
			);
		} else if ( activity.not_shared_title ) {
			body = el( 'p', null, el( 'strong', null, activity.not_shared_title ), ' ', activity.not_shared );
		} else {
			body = el( 'p', { className: 'pq-ed-muted' }, __( 'PressQueue has not shared this post.', 'pressqueue' ) );
		}
		return el(
			'div',
			{ className: 'pq-ed-activity' },
			body,
			activity.url && config.canViewLog ? el( 'p', { className: 'pq-ed-small' }, el( 'a', { href: activity.url }, __( 'See in Activity', 'pressqueue' ) ) ) : null
		);
	}

	function timezoneOptions( siteZone, siteLabel, current ) {
		/* translators: %s: site time zone, for example Europe/Berlin. */
		var options = [ { value: siteZone, label: sprintf( __( 'Site time zone (%s)', 'pressqueue' ), siteLabel || siteZone ) } ];
		var seen = {};
		seen[ siteZone ] = true;
		if ( current && ! seen[ current ] ) {
			options.push( { value: current, label: current } );
			seen[ current ] = true;
		}
		var zones = [];
		try {
			zones = typeof Intl !== 'undefined' && Intl.supportedValuesOf ? Intl.supportedValuesOf( 'timeZone' ) : [];
		} catch ( error ) {
			zones = [];
		}
		zones.forEach( function ( zone ) {
			if ( ! seen[ zone ] ) {
				seen[ zone ] = true;
				options.push( { value: zone, label: zone.replace( /_/g, ' ' ) } );
			}
		} );
		return options;
	}

	function MediaPicker( props ) {
		var ids = props.ids;
		var media = useSelect( function ( select ) {
			var core = select( 'core' );
			return ids.map( function ( id ) {
				return core && core.getMedia ? core.getMedia( id, { context: 'view' } ) : null;
			} );
		}, [ ids.join( ',' ) ] );

		function remove( id ) {
			props.onChange( ids.filter( function ( item ) {
				return item !== id;
			} ) );
		}

		return el(
			'div',
			{ className: 'pq-ed-media' },
			ids.length ? el(
				'ul',
				{ className: 'pq-ed-thumbs' },
				ids.map( function ( id, index ) {
					var item = media[ index ];
					var sizes = item && item.media_details && item.media_details.sizes ? item.media_details.sizes : {};
					var url = sizes.thumbnail ? sizes.thumbnail.source_url : ( item ? item.source_url : '' );
					return el(
						'li',
						{ key: id },
						url ? el( 'img', { src: url, alt: item && item.alt_text ? item.alt_text : '' } ) : el( 'span', { className: 'pq-ed-thumb-missing' }, '#' + id ),
						el( Button, {
							variant: 'link',
							isDestructive: true,
							disabled: props.disabled,
							onClick: function () {
								remove( id );
							},
							label: __( 'Remove image', 'pressqueue' ),
							className: 'pq-ed-thumb-remove'
						}, __( 'Remove', 'pressqueue' ) )
					);
				} )
			) : null,
			MediaUpload ? el( MediaUpload, {
				allowedTypes: [ 'image' ],
				multiple: true,
				gallery: false,
				value: ids,
				title: __( 'Choose images to share', 'pressqueue' ),
				onSelect: function ( items ) {
					var picked = ( Array.isArray( items ) ? items : [ items ] ).map( function ( item ) {
						return parseInt( item && item.id, 10 );
					} ).filter( function ( id ) {
						return id > 0;
					} );
					props.onChange( picked.slice( 0, 4 ) );
				},
				render: function ( upload ) {
					return el( Button, { variant: 'secondary', onClick: upload.open, disabled: props.disabled }, ids.length ? __( 'Change images', 'pressqueue' ) : __( 'Choose images', 'pressqueue' ) );
				}
			} ) : null,
			el( 'p', { className: 'pq-ed-help' }, __( 'Up to four images from the media library.', 'pressqueue' ) )
		);
	}

	function Customize( props ) {
		var controls = props.controls;
		var display = props.display;
		var editor = props.editor;
		var disabled = props.disabled;
		var openPair = useState( hasCustomizations( controls ) );
		var open = openPair[ 0 ];
		var setOpen = openPair[ 1 ];
		var message = controls.message || { mode: 'inherit' };
		var schedule = controls.schedule || { mode: 'inherit' };
		var media = controls.media || { mode: 'inherit' };
		var channels = controls.channels || { mode: 'inherit' };
		var info = display && display.message ? display.message : {};
		var rule = display && display.rule ? display.rule : {};
		var stored = channels.mode === 'override' ? channels.channel_public_ids || [] : [];
		var listed = ( editor.channels || [] ).filter( function ( channel ) {
			return ( channel.in_rules && channel.usable ) || stored.indexOf( channel.public_id ) !== -1;
		} );
		var selectedIds = channels.mode === 'override' ? stored : listed.map( function ( channel ) {
			return channel.public_id;
		} );
		var siteZone = editor.timezone || 'UTC';
		var mediaIds = media.mode === 'post_media' && media.post_media && media.post_media.attachment_ids ? media.post_media.attachment_ids : [];
		var schedules = config.schedules || {};
		var images = config.images || {};

		function setMessage( value ) {
			props.onChange( withControl( controls, 'message', plainText( value ) === '' ? { mode: 'inherit' } : { mode: 'override', value: value } ) );
		}

		function toggleChannel( publicId, checked ) {
			var next = selectedIds.filter( function ( id ) {
				return id !== publicId;
			} );
			if ( checked ) {
				next.push( publicId );
			}
			next.sort();
			var all = listed.every( function ( channel ) {
				return next.indexOf( channel.public_id ) !== -1;
			} );
			// Once a post restricts its channels, it keeps the restriction until the writer resets it.
			// Ticking every visible channel must not silently allow channels that are switched off now.
			if ( all && channels.mode !== 'override' ) {
				props.onChange( withControl( controls, 'channels', { mode: 'inherit' } ) );
				return;
			}
			props.onChange( withControl( controls, 'channels', { mode: 'override', channel_public_ids: next } ) );
		}

		function resetChannels() {
			props.onChange( withControl( controls, 'channels', { mode: 'inherit' } ) );
		}

		function setScheduleMode( mode ) {
			props.onChange( withControl( controls, 'schedule', mode === 'explicit_time' ? {
				mode: 'explicit_time',
				local_date: schedule.local_date || '',
				local_time: schedule.local_time || '',
				timezone: schedule.timezone || siteZone
			} : { mode: mode } ) );
		}

		function setScheduleField( field, value ) {
			var next = {
				mode: 'explicit_time',
				local_date: schedule.local_date || '',
				local_time: schedule.local_time || '',
				timezone: schedule.timezone || siteZone
			};
			next[ field ] = value;
			props.onChange( withControl( controls, 'schedule', next ) );
		}

		function setMediaMode( mode ) {
			props.onChange( withControl( controls, 'media', mode === 'post_media' ? {
				mode: 'post_media',
				post_media: { attachment_ids: mediaIds }
			} : { mode: mode } ) );
		}

		function setMediaIds( ids ) {
			props.onChange( withControl( controls, 'media', { mode: 'post_media', post_media: { attachment_ids: ids } } ) );
		}

		var scheduleOptions = Object.keys( schedules ).map( function ( value ) {
			return { value: value, label: schedules[ value ] };
		} );
		var imageOptions = Object.keys( images ).filter( function ( value ) {
			return value !== 'featured_image' || media.mode === 'featured_image';
		} ).map( function ( value ) {
			return { value: value, label: images[ value ] };
		} );

		function fromRule( text ) {
			/* translators: %s: setting used by the matching rule, for example "Add to the end of the Buffer queue". */
			return text ? sprintf( __( 'From the rule: %s', 'pressqueue' ), text ) : undefined;
		}

		var count = null;
		if ( info.limit ) {
			var text = plainText( message.mode === 'override' ? message.value : '' ) || info.rule_message || '';
			var used = charLength( text ) + 2 + ( info.url_length || 0 );
			count = used <= info.limit ? {
				over: false,
				/* translators: 1: characters used, 2: character limit. */
				text: sprintf( __( '%1$d of %2$d characters, including the link.', 'pressqueue' ), used, info.limit )
			} : {
				over: true,
				/* translators: 1: number of characters over the limit, 2: social network name. */
				text: sprintf( __( '%1$d characters too long for %2$s. The link counts too.', 'pressqueue' ), used - info.limit, info.limit_service || '' )
			};
		}

		var explicit = schedule.mode === 'explicit_time';
		var explicitIncomplete = explicit && ( ! schedule.local_date || ! schedule.local_time );

		return el(
			'div',
			{ className: 'pq-ed-more' + ( open ? ' is-open' : '' ) },
			el( Button, {
				variant: 'link',
				className: 'pq-ed-more-toggle',
				'aria-expanded': open,
				'aria-controls': 'pq-ed-more-body',
				onClick: function () {
					setOpen( ! open );
				}
			}, __( 'Customize for this post', 'pressqueue' ), hasCustomizations( controls ) ? el( 'span', { className: 'pq-ed-changed' }, __( 'Changed', 'pressqueue' ) ) : null ),
			open ? el(
				'div',
				{ className: 'pq-ed-more-body', id: 'pq-ed-more-body' },
				el( TextareaControl, {
					label: __( 'Message', 'pressqueue' ),
					value: message.mode === 'override' ? message.value || '' : '',
					placeholder: info.rule_message || '',
					disabled: disabled,
					rows: 3,
					help: el(
						'span',
						null,
						__( 'Leave empty to use the rule\'s message.', 'pressqueue' ),
						count ? el( 'span', { className: 'pq-ed-count' + ( count.over ? ' is-over' : '' ), 'aria-live': 'polite' }, ' ' + count.text ) : null
					),
					onChange: setMessage,
					__nextHasNoMarginBottom: true
				} ),
				listed.length ? el(
					'fieldset',
					{ className: 'pq-ed-field' },
					el( 'legend', null, __( 'Channels', 'pressqueue' ) ),
					listed.map( function ( channel ) {
						return el(
							'label',
							{ key: channel.public_id, className: 'pq-ed-check' },
							el( 'input', {
								type: 'checkbox',
								checked: selectedIds.indexOf( channel.public_id ) !== -1,
								disabled: disabled,
								onChange: function ( event ) {
									toggleChannel( channel.public_id, !! event.target.checked );
								}
							} ),
							el( ChannelName, { channel: channel } )
						);
					} ),
					el( 'p', { className: 'pq-ed-help' }, __( 'Untick a channel to leave it out for this post.', 'pressqueue' ) )
				) : null,
				channels.mode === 'override' ? el( Button, { variant: 'link', onClick: resetChannels, disabled: disabled }, __( 'Use the channels from the rule again', 'pressqueue' ) ) : null,
				el( SelectControl, {
					label: __( 'Timing', 'pressqueue' ),
					value: schedule.mode,
					options: scheduleOptions,
					disabled: disabled,
					help: schedule.mode === 'inherit' ? fromRule( rule.timing ) : undefined,
					onChange: setScheduleMode,
					__nextHasNoMarginBottom: true
				} ),
				explicit ? el(
					'div',
					{ className: 'pq-ed-datetime' },
					el(
						'div',
						{ className: 'pq-ed-field-row' },
						el( TextControl, {
							label: __( 'Date', 'pressqueue' ),
							type: 'date',
							value: schedule.local_date || '',
							disabled: disabled,
							onChange: function ( value ) {
								setScheduleField( 'local_date', value );
							},
							__nextHasNoMarginBottom: true
						} ),
						el( TextControl, {
							label: __( 'Time', 'pressqueue' ),
							type: 'time',
							value: schedule.local_time || '',
							disabled: disabled,
							onChange: function ( value ) {
								setScheduleField( 'local_time', value );
							},
							__nextHasNoMarginBottom: true
						} )
					),
					el( SelectControl, {
						label: __( 'Time zone', 'pressqueue' ),
						value: schedule.timezone || siteZone,
						options: timezoneOptions( siteZone, editor.timezone_label, schedule.timezone ),
						disabled: disabled,
						onChange: function ( value ) {
							setScheduleField( 'timezone', value );
						},
						__nextHasNoMarginBottom: true
					} ),
					explicitIncomplete ? el( 'p', { className: 'pq-ed-help pq-ed-warning' }, __( 'Pick a date and a time.', 'pressqueue' ) ) : null
				) : null,
				el( SelectControl, {
					label: __( 'Image', 'pressqueue' ),
					value: media.mode,
					options: imageOptions,
					disabled: disabled,
					help: media.mode === 'inherit' ? fromRule( rule.image ) : undefined,
					onChange: setMediaMode,
					__nextHasNoMarginBottom: true
				} ),
				media.mode === 'post_media' ? el( MediaPicker, { ids: mediaIds, disabled: disabled, onChange: setMediaIds } ) : null
			) : null
		);
	}

	function setSharingOn( sharing, checked ) {
		var data = sharing.data;
		var policy = 'exclude';
		if ( checked ) {
			policy = data.saved.policy === 'include' ? 'include' : 'inherit';
		}
		sharing.setControls( withControl( data.controls, 'policy', policy ) );
	}

	// The "Share this post" switch. A matching "never share" rule shows it off and locked
	// without changing the stored choice; it returns to normal once the rule stops matching.
	function ShareToggle( props ) {
		var keptOff = props.keptOff;
		return el( ToggleControl, {
			label: __( 'Share this post', 'pressqueue' ),
			className: keptOff ? 'pq-ed-toggle is-kept-off' : 'pq-ed-toggle',
			checked: keptOff ? false : props.on,
			disabled: props.disabled || !! keptOff,
			help: keptOff || undefined,
			onChange: function ( checked ) {
				if ( ! keptOff ) {
					props.onChange( checked );
				}
			},
			__nextHasNoMarginBottom: true
		} );
	}

	function ModeNote( props ) {
		var editor = props.editor || {};
		return editor.mode_note ? el( 'p', { className: 'pq-ed-note pq-ed-note--' + ( editor.sharing_mode || 'test' ) }, editor.mode_note ) : null;
	}

	// A short version for the "Are you ready to publish?" sidebar.
	function PrePublishBody( props ) {
		var sharing = props.sharing;
		var data = sharing.data;
		var post = usePostFacts( data.facts || {} );
		var preview = usePreview( sharing, post, props.postId );

		if ( ! data.loaded ) {
			return null;
		}

		var on = isOn( data.controls );
		var keptOff = preview.keptOff;
		var summary = null;
		if ( ! keptOff ) {
			summary = on ? el( Summary, { preview: preview, published: false, password: post.password, isPrivate: post.status === 'private' } ) : el( 'p', { className: 'pq-ed-muted' }, __( 'This post will not be shared.', 'pressqueue' ) );
		}
		return el(
			'div',
			{ className: 'pq-editor pq-editor--prepublish' },
			el( ShareToggle, {
				on: on,
				keptOff: keptOff,
				disabled: data.saving || post.coreSaving,
				onChange: function ( checked ) {
					setSharingOn( sharing, checked );
				}
			} ),
			summary,
			el( ModeNote, { editor: data.editor } )
		);
	}

	function PanelBody( props ) {
		var sharing = props.sharing;
		var data = sharing.data;
		var editor = data.editor || {};
		var post = usePostFacts( data.facts || {} );
		var preview = usePreview( sharing, post, props.postId );
		var published = post.savedStatus === 'publish';
		var controls = data.controls;
		var on = isOn( controls );
		var disabled = ! data.loaded || data.saving || post.coreSaving;
		var keptOff = preview.keptOff;
		var activityItems = data.activity && data.activity.items ? data.activity.items : [];
		// When a "never share" rule matches, the switch already says why nothing was shared.
		var showActivity = !! data.activity && ( ! keptOff || activityItems.length > 0 );

		useEffect( function () {
			if ( ! data.loaded || ! published ) {
				return undefined;
			}
			sharing.loadActivity( false );
			return function () {
				sharing.stopActivity();
			};
		}, [ data.loaded, published, sharing.activityTick, props.postId ] );

		function setOn( checked ) {
			setSharingOn( sharing, checked );
		}

		if ( data.loadError ) {
			return el( Notice, {
				status: 'error',
				isDismissible: false,
				actions: [ { label: __( 'Try again', 'pressqueue' ), onClick: sharing.reload } ]
			}, data.loadError );
		}

		if ( ! data.loaded ) {
			return el( 'p', { className: 'pq-editor pq-ed-muted pq-ed-checking' }, Spinner ? el( Spinner ) : null, __( 'Loading sharing settings…', 'pressqueue' ) );
		}

		return el(
			'div',
			{ className: 'pq-editor pq-editor--panel' },
			data.saveError ? el( Notice, {
				status: 'error',
				isDismissible: false,
				actions: data.conflict ? [ { label: __( 'Load the latest settings', 'pressqueue' ), onClick: sharing.reload } ] : []
			}, data.saveError ) : null,
			el( ShareToggle, {
				on: on,
				keptOff: keptOff,
				disabled: disabled,
				onChange: setOn
			} ),
			published && showActivity ? el( Activity, { activity: data.activity } ) : null,
			keptOff ? null : ( on ? el( Summary, { preview: preview, published: published, password: post.password, isPrivate: post.status === 'private' } ) : el(
				'p',
				{ className: 'pq-ed-muted' },
				published ? __( 'Sharing is off for this post. Updates and Share old posts leave it out.', 'pressqueue' ) : __( 'This post will not be shared. You can switch it on any time before publishing.', 'pressqueue' )
			) ),
			! keptOff && ! published && editor.opt_in_hint ? el( 'p', { className: 'pq-ed-hint' }, editor.opt_in_hint ) : null,
			el( ModeNote, { editor: editor } ),
			keptOff ? null : el( Customize, {
				controls: controls,
				display: preview.display,
				editor: editor,
				disabled: disabled,
				onChange: sharing.setControls
			} ),
			! SAVE_WITH_POST && sharing.dirty ? el( Button, {
				variant: 'secondary',
				isBusy: data.saving,
				disabled: data.saving,
				onClick: function () {
					sharing.save().catch( function () {} );
				}
			}, __( 'Save sharing settings', 'pressqueue' ) ) : null
		);
	}

	function SharingPlugin() {
		var postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );
		var enabled = config.canAccess !== false;
		var sharing = useSharingState( postId, enabled );

		if ( ! enabled || sharing.data.unavailable ) {
			return null;
		}

		return el(
			Fragment,
			null,
			el(
				Panel,
				{
					name: 'pressqueue-post-controls',
					title: __( 'Social sharing', 'pressqueue' ),
					className: 'pq-editor-panel'
				},
				el( PanelBody, { sharing: sharing, postId: postId } )
			),
			PrePublishPanel ? el(
				PrePublishPanel,
				{
					title: __( 'Social sharing', 'pressqueue' ),
					initialOpen: true,
					className: 'pq-editor-panel'
				},
				el( PrePublishBody, { sharing: sharing, postId: postId } )
			) : null
		);
	}

	wp.plugins.registerPlugin( 'pressqueue-post-controls', {
		icon: 'share',
		render: SharingPlugin
	} );
}( window.wp, window.pressqueuePostControls ) );
