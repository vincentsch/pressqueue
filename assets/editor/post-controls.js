( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.editPost || ! wp.element || ! wp.components || ! wp.apiFetch || ! wp.data ) {
		return;
	}

	var createElement = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var Panel = wp.editPost.PluginDocumentSettingPanel;
	var Button = wp.components.Button;
	var CheckboxControl = wp.components.CheckboxControl;
	var Notice = wp.components.Notice;
	var SelectControl = wp.components.SelectControl;
	var TextareaControl = wp.components.TextareaControl;
	var TextControl = wp.components.TextControl;

	function inheritedBundle() {
		return {
			schema_version: 2,
			policy: 'inherit',
			channels: {
				mode: 'inherit'
			},
			message: {
				mode: 'inherit'
			},
			schedule: {
				mode: 'inherit'
			},
			media: {
				mode: 'inherit'
			}
		};
	}

	function normalizeControls( controls ) {
		if ( ! controls || ! controls.channels ) {
			return inheritedBundle();
		}
		return controls;
	}

	function mergeControls( controls, changes ) {
		var base = normalizeControls( controls );
		return Object.assign( {}, base, changes, { schema_version: 2 } );
	}

	function attachmentIdsFromText( value ) {
		return String( value || '' ).split( ',' ).map( function ( item ) {
			return item.trim();
		} ).filter( function ( item ) {
			return item !== '';
		} );
	}

	function PanelContent() {
		var postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );
		var postType = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );
		var edited = useSelect( function ( select ) {
			return {
				author: select( 'core/editor' ).getEditedPostAttribute( 'author' ) || 0,
				attributes: select( 'core/editor' ).getEditedPostAttributes ? select( 'core/editor' ).getEditedPostAttributes() : {},
				categories: select( 'core/editor' ).getEditedPostAttribute( 'categories' ) || [],
				content: select( 'core/editor' ).getEditedPostContent() || '',
				dateGmt: select( 'core/editor' ).getEditedPostAttribute( 'date_gmt' ) || '0000-00-00 00:00:00',
				excerpt: select( 'core/editor' ).getEditedPostAttribute( 'excerpt' ) || '',
				link: select( 'core/editor' ).getPermalink ? select( 'core/editor' ).getPermalink() : '',
				meta: select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {},
				modifiedGmt: select( 'core/editor' ).getEditedPostAttribute( 'modified_gmt' ) || '0000-00-00 00:00:00',
				status: select( 'core/editor' ).getEditedPostAttribute( 'status' ) || 'draft',
				tags: select( 'core/editor' ).getEditedPostAttribute( 'tags' ) || [],
				title: select( 'core/editor' ).getEditedPostAttribute( 'title' ) || ''
			};
		}, [] );
		var state = useControlState( postId );
		var controls = normalizeControls( state.controls );
		var channels = state.effectiveFacts.channels || [];
		var selected = controls.channels.channel_public_ids || [];
		var message = controls.message || { mode: 'inherit' };
		var schedule = controls.schedule || { mode: 'inherit' };
		var media = controls.media || { mode: 'inherit' };

		function setPolicy( policy ) {
			state.setControls( mergeControls( controls, { policy: policy } ) );
		}

		function setMode( mode ) {
			state.setControls( mergeControls( controls, {
				channels: mode === 'override' ? {
					mode: 'override',
					channel_public_ids: selected
				} : {
					mode: 'inherit'
				}
			} ) );
		}

		function toggleChannel( publicId, checked ) {
			var next = selected.filter( function ( item ) {
				return item !== publicId;
			} );
			if ( checked ) {
				next.push( publicId );
			}
			next.sort();
			state.setControls( mergeControls( controls, {
				channels: {
					mode: 'override',
					channel_public_ids: next
				}
			} ) );
		}

		function setMessageMode( mode ) {
			state.setControls( mergeControls( controls, {
				message: mode === 'override' ? {
					mode: 'override',
					value: message.value || ''
				} : {
					mode: 'inherit'
				}
			} ) );
		}

		function setMessageValue( value ) {
			state.setControls( mergeControls( controls, {
				message: {
					mode: 'override',
					value: value
				}
			} ) );
		}

		function setScheduleMode( mode ) {
			var explicit = {
				mode: 'explicit_time',
				local_date: schedule.local_date || '',
				local_time: schedule.local_time || '',
				timezone: schedule.timezone || ''
			};
			if ( schedule.utc_offset ) {
				explicit.utc_offset = schedule.utc_offset;
			}

			state.setControls( mergeControls( controls, {
				schedule: mode === 'explicit_time' ? explicit : {
					mode: mode
				}
			} ) );
		}

		function setScheduleField( key, value ) {
			var next = Object.assign( {}, schedule, { mode: 'explicit_time' } );
			if ( key === 'utc_offset' && value === '' ) {
				delete next.utc_offset;
			} else {
				next[ key ] = value;
			}
			state.setControls( mergeControls( controls, { schedule: next } ) );
		}

		function setMediaMode( mode ) {
			state.setControls( mergeControls( controls, {
				media: mode === 'post_media' ? {
					mode: 'post_media',
					post_media: {
						attachment_ids: media.post_media && media.post_media.attachment_ids ? media.post_media.attachment_ids : []
					}
				} : {
					mode: mode
				}
			} ) );
		}

		function setMediaIds( value ) {
			state.setControls( mergeControls( controls, {
				media: {
					mode: 'post_media',
					post_media: {
						attachment_ids: attachmentIdsFromText( value )
					}
				}
			} ) );
		}

		function save() {
			state.saveControls();
		}

		function preview() {
			state.previewControls( {
				post_type: postType || 'post',
				post_status: edited.status,
				author_id: parseInt( edited.author, 10 ) || 0,
				canonical_url: edited.link || '',
				published_at_gmt: normalizeDate( edited.dateGmt ),
				modified_at_gmt: normalizeDate( edited.modifiedGmt ),
				title: typeof edited.title === 'object' && edited.title.raw ? edited.title.raw : String( edited.title || '' ),
				excerpt: typeof edited.excerpt === 'object' && edited.excerpt.raw ? edited.excerpt.raw : String( edited.excerpt || '' ),
				content: edited.content,
				terms: editorTerms( edited, state.effectiveFacts.rule_relevant_taxonomies || [] ),
				metadata: editorMetadata( edited.meta, state.effectiveFacts.rule_relevant_metadata_keys || [] )
			} );
		}

		if ( state.unavailable ) {
			return null;
		}

		return createElement(
			Panel,
			{
				name: 'pressqueue-post-controls',
				title: 'PressQueue'
			},
			state.notice ? createElement( Notice, { status: state.noticeStatus, isDismissible: false }, state.notice ) : null,
			createElement( SelectControl, {
				label: 'Policy',
				value: controls.policy,
				options: [
					{ label: 'Inherit', value: 'inherit' },
					{ label: 'Include', value: 'include' },
					{ label: 'Exclude', value: 'exclude' }
				],
				onChange: setPolicy
			} ),
			createElement( SelectControl, {
				label: 'Destinations',
				value: controls.channels.mode,
				options: [
					{ label: 'Inherit', value: 'inherit' },
					{ label: 'Restrict channels', value: 'override' }
				],
				onChange: setMode
			} ),
			controls.channels.mode === 'override' ? channels.map( function ( channel ) {
				return createElement( CheckboxControl, {
					key: channel.public_id,
					label: channel.label,
					checked: selected.indexOf( channel.public_id ) !== -1,
					onChange: function ( checked ) {
						toggleChannel( channel.public_id, checked );
					}
				} );
			} ) : null,
			createElement( SelectControl, {
				label: 'Message',
				value: message.mode,
				options: [
					{ label: 'Inherit', value: 'inherit' },
					{ label: 'Override', value: 'override' }
				],
				onChange: setMessageMode
			} ),
			message.mode === 'override' ? createElement( TextareaControl, {
				label: 'Message text',
				value: message.value || '',
				onChange: setMessageValue
			} ) : null,
			createElement( SelectControl, {
				label: 'Schedule',
				value: schedule.mode,
				options: [
					{ label: 'Inherit', value: 'inherit' },
					{ label: 'Immediate', value: 'immediate' },
					{ label: 'Queue next', value: 'queue_next' },
					{ label: 'Queue end', value: 'queue_end' },
					{ label: 'Explicit time', value: 'explicit_time' }
				],
				onChange: setScheduleMode
			} ),
			schedule.mode === 'explicit_time' ? createElement( TextControl, {
				label: 'Local date',
				type: 'date',
				value: schedule.local_date || '',
				onChange: function ( value ) {
					setScheduleField( 'local_date', value );
				}
			} ) : null,
			schedule.mode === 'explicit_time' ? createElement( TextControl, {
				label: 'Local time',
				type: 'time',
				value: schedule.local_time || '',
				onChange: function ( value ) {
					setScheduleField( 'local_time', value );
				}
			} ) : null,
			schedule.mode === 'explicit_time' ? createElement( TextControl, {
				label: 'Timezone',
				value: schedule.timezone || '',
				onChange: function ( value ) {
					setScheduleField( 'timezone', value );
				}
			} ) : null,
			schedule.mode === 'explicit_time' ? createElement( TextControl, {
				label: 'UTC offset',
				value: schedule.utc_offset || '',
				onChange: function ( value ) {
					setScheduleField( 'utc_offset', value );
				}
			} ) : null,
			createElement( SelectControl, {
				label: 'Media',
				value: media.mode,
				options: [
					{ label: 'Inherit', value: 'inherit' },
					{ label: 'None', value: 'none' },
					{ label: 'Featured image', value: 'featured_image' },
					{ label: 'Post media', value: 'post_media' }
				],
				onChange: setMediaMode
			} ),
			media.mode === 'post_media' ? createElement( TextControl, {
				label: 'Attachment IDs',
				value: media.post_media && media.post_media.attachment_ids ? media.post_media.attachment_ids.join( ',' ) : '',
				onChange: setMediaIds
			} ) : null,
			state.preview ? createElement( 'pre', {}, JSON.stringify( state.preview, null, 2 ) ) : null,
			createElement( Button, { variant: 'secondary', onClick: preview, isBusy: state.busy }, 'Preview' ),
			createElement( Button, { variant: 'primary', onClick: save, isBusy: state.busy }, 'Save Controls' )
		);
	}

	function useControlState( postId ) {
		var defaultState = {
			controlBundleHash: '',
			controls: inheritedBundle(),
			effectiveFacts: {},
			notice: '',
			noticeStatus: 'info',
			preview: null,
			unavailable: false
		};
		var pair = useState( defaultState );
		var data = pair[ 0 ];
		var setData = pair[ 1 ];
		var busyPair = useState( false );
		var busy = busyPair[ 0 ];
		var setBusy = busyPair[ 1 ];

		useEffect( function () {
			if ( ! postId ) {
				return;
			}
			setBusy( true );
			wp.apiFetch( { path: '/pressqueue/v1/posts/' + postId + '/controls' } )
				.then( function ( response ) {
					setData( {
						controlBundleHash: response.control_bundle_hash,
						controls: normalizeControls( response.controls ),
						effectiveFacts: response.effective_facts || {},
						notice: '',
						noticeStatus: 'info',
						preview: null,
						unavailable: false
					} );
				} )
				.catch( function () {
					setData( Object.assign( {}, defaultState, { unavailable: true } ) );
				} )
				.finally( function () {
					setBusy( false );
				} );
		}, [ postId ] );

		return {
			busy: busy,
			controlBundleHash: data.controlBundleHash,
			controls: data.controls,
			effectiveFacts: data.effectiveFacts,
			notice: data.notice,
			noticeStatus: data.noticeStatus,
			preview: data.preview,
			unavailable: data.unavailable,
			setControls: function ( controls ) {
				setData( Object.assign( {}, data, { controls: controls } ) );
			},
			saveControls: function () {
				setBusy( true );
				wp.apiFetch( {
					path: '/pressqueue/v1/posts/' + postId + '/controls',
					method: 'PUT',
					data: {
						base_control_bundle_hash: data.controlBundleHash,
						controls: data.controls
					}
				} )
					.then( function ( response ) {
						setData( Object.assign( {}, data, {
							controlBundleHash: response.control_bundle_hash,
							controls: normalizeControls( response.controls ),
							effectiveFacts: response.effective_facts || {},
							notice: 'Controls saved.',
							noticeStatus: 'success'
						} ) );
					} )
					.catch( function ( error ) {
						setData( Object.assign( {}, data, {
							notice: error && error.message ? error.message : 'Controls could not be saved.',
							noticeStatus: 'error'
						} ) );
					} )
					.finally( function () {
						setBusy( false );
					} );
			},
			previewControls: function ( snapshot ) {
				setBusy( true );
				wp.apiFetch( {
					path: '/pressqueue/v1/posts/' + postId + '/preview',
					method: 'POST',
					data: {
						controls: data.controls,
						editor_snapshot: snapshot
					}
				} )
					.then( function ( response ) {
						setData( Object.assign( {}, data, {
							notice: 'Preview ready.',
							noticeStatus: 'success',
							preview: response
						} ) );
					} )
					.catch( function ( error ) {
						setData( Object.assign( {}, data, {
							notice: error && error.message ? error.message : 'Preview could not be created.',
							noticeStatus: 'error',
							preview: null
						} ) );
					} )
					.finally( function () {
						setBusy( false );
					} );
			}
		};
	}

	function normalizeDate( value ) {
		if ( ! value || value.indexOf( 'T' ) === -1 ) {
			return value || '0000-00-00 00:00:00';
		}
		return value.replace( 'T', ' ' ).replace( 'Z', '' ).slice( 0, 19 );
	}

	function editorTerms( edited, taxonomies ) {
		var terms = [];
		( edited.categories || [] ).forEach( function ( termId ) {
			terms.push( {
				taxonomy: 'category',
				term_id: parseInt( termId, 10 )
			} );
		} );
		( edited.tags || [] ).forEach( function ( termId ) {
			terms.push( {
				taxonomy: 'post_tag',
				term_id: parseInt( termId, 10 )
			} );
		} );
		( taxonomies || [] ).forEach( function ( taxonomy ) {
			var restBase = taxonomy.rest_base || taxonomy.taxonomy;
			var values = edited.attributes && Array.isArray( edited.attributes[ restBase ] ) ? edited.attributes[ restBase ] : [];
			values.forEach( function ( termId ) {
				terms.push( {
					taxonomy: taxonomy.taxonomy,
					term_id: parseInt( termId, 10 )
				} );
			} );
		} );
		var seen = {};
		return terms.filter( function ( term ) {
			var key = term.taxonomy + ':' + term.term_id;
			if ( seen[ key ] ) {
				return false;
			}
			seen[ key ] = true;
			return term.term_id > 0;
		} );
	}

	function editorMetadata( meta, keys ) {
		var output = {};
		keys.forEach( function ( key ) {
			output[ key ] = Object.prototype.hasOwnProperty.call( meta, key ) ? meta[ key ] : null;
		} );
		return output;
	}

	wp.plugins.registerPlugin( 'pressqueue-post-controls', {
		render: PanelContent
	} );
}( window.wp ) );
