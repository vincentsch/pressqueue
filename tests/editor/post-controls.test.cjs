'use strict';

const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const { test } = require( 'node:test' );
const vm = require( 'node:vm' );

const SOURCE = readFileSync( require.resolve( '../../assets/editor/post-controls.js' ), 'utf8' );
const MARKER = 'pressqueue_sharing_changed';

// Objects created inside the script context have a different prototype chain.
function plain( value ) {
	return JSON.parse( JSON.stringify( value ) );
}

// A tiny renderer that runs function components with per-instance hooks, so the
// real registered plugin can be exercised without React.
function createRenderer() {
	const instances = new Map();
	let effects = [];
	let seen = new Set();
	let current = null;

	const element = {
		createElement: ( type, props, ...children ) => ( { type, props: props || {}, children: children.flat( Infinity ) } ),
		Fragment: 'Fragment',
		useState( initial ) {
			const instance = current;
			const index = instance.index++;
			if ( ! ( index in instance.hooks ) ) {
				instance.hooks[ index ] = { value: typeof initial === 'function' ? initial() : initial };
			}
			const hook = instance.hooks[ index ];
			return [ hook.value, value => {
				hook.value = typeof value === 'function' ? value( hook.value ) : value;
			} ];
		},
		useRef( initial ) {
			const instance = current;
			const index = instance.index++;
			if ( ! ( index in instance.hooks ) ) {
				instance.hooks[ index ] = { current: initial };
			}
			return instance.hooks[ index ];
		},
		useEffect( callback, dependencies ) {
			const instance = current;
			const index = instance.index++;
			const previous = instance.hooks[ index ];
			const changed = ! previous || ! dependencies || ! previous.dependencies
				|| dependencies.length !== previous.dependencies.length
				|| dependencies.some( ( value, i ) => value !== previous.dependencies[ i ] );
			if ( changed ) {
				const slot = previous || {};
				instance.hooks[ index ] = slot;
				effects.push( () => {
					if ( slot.cleanup ) {
						slot.cleanup();
					}
					slot.dependencies = dependencies;
					const cleanup = callback();
					slot.cleanup = typeof cleanup === 'function' ? cleanup : null;
				} );
			}
		}
	};

	function childPath( path, node, index ) {
		return path + '.' + ( node && node.props && node.props.key != null ? 'k' + node.props.key : index );
	}

	function expand( node, path ) {
		if ( Array.isArray( node ) ) {
			return node.map( ( child, index ) => expand( child, childPath( path, child, index ) ) );
		}
		if ( node === null || node === undefined || typeof node !== 'object' ) {
			return node;
		}
		if ( typeof node.type === 'function' ) {
			const id = path + '>' + ( node.type.name || 'component' );
			let instance = instances.get( id );
			if ( ! instance ) {
				instance = { hooks: [], index: 0 };
				instances.set( id, instance );
			}
			seen.add( id );
			const previous = current;
			current = instance;
			instance.index = 0;
			const output = node.type( Object.assign( {}, node.props, node.children.length ? { children: node.children } : {} ) );
			current = previous;
			return expand( output, id );
		}
		return {
			type: node.type,
			props: node.props,
			children: node.children.map( ( child, index ) => expand( child, childPath( path, child, index ) ) )
		};
	}

	function cleanup( instance ) {
		instance.hooks.forEach( hook => {
			if ( hook && hook.cleanup ) {
				hook.cleanup();
				hook.cleanup = null;
			}
		} );
	}

	return {
		element,
		render( root ) {
			seen = new Set();
			const tree = expand( root, 'root' );
			for ( const [ id, instance ] of instances ) {
				if ( ! seen.has( id ) ) {
					cleanup( instance );
					instances.delete( id );
				}
			}
			const run = effects;
			effects = [];
			run.forEach( effect => effect() );
			return tree;
		},
		unmount() {
			for ( const instance of instances.values() ) {
				cleanup( instance );
			}
			instances.clear();
		}
	};
}

function controlsResponse( policy = 'inherit', extra = {} ) {
	return Object.assign( {
		control_bundle_hash: 'hash-' + policy,
		controls: { schema_version: 2, policy, channels: { mode: 'inherit' }, message: { mode: 'inherit' }, schedule: { mode: 'inherit' }, media: { mode: 'inherit' } },
		editor: {
			sharing_mode: 'test',
			mode_note: 'Test mode is on. Nothing is sent to Buffer yet.',
			new_post_sharing: 'automatic',
			opt_in_hint: '',
			timezone: 'Europe/Berlin',
			channels: [
				{ public_id: 'CH1', service: 'linkedin', service_name: 'LinkedIn', monogram: 'in', name: 'Larkfield Bakery', in_rules: true, usable: true },
				{ public_id: 'CH2', service: 'twitter', service_name: 'X', monogram: 'X', name: '@larkfieldbakes', in_rules: true, usable: true }
			],
			activity_url: 'admin.php?page=pressqueue-deliveries&post_id=1'
		},
		effective_facts: { rule_relevant_taxonomies: [], rule_relevant_metadata_keys: [] }
	}, extra );
}

function previewResponse( channels = [ 'Larkfield Bakery' ] ) {
	return {
		display: {
			will_share: channels.length > 0,
			heading: 'When you publish, PressQueue sends it to:',
			channels: channels.map( ( name, index ) => ( { public_id: 'CH' + ( index + 1 ), service: 'linkedin', service_name: 'LinkedIn', monogram: 'in', name, timing: 'End of the Buffer queue', image: 'with the featured image' } ) ),
			details: 'End of the Buffer queue · with the featured image',
			reason: channels.length ? '' : 'No sharing rule matches this post.',
			left_out: [],
			message: { rule_message: 'We are hiring', url_length: 30, limit: 280, limit_service: 'X' },
			rule: { name: 'Share new posts', timing: 'Add to the end of the Buffer queue', image: 'Featured image when there is one' }
		}
	};
}

function harness( options = {} ) {
	const renderer = createRenderer();
	const pending = [];
	// Pending timers by a unique ID, like real timer handles.
	const timers = new Map();
	let nextTimer = 0;
	const filters = {};
	const actions = {};
	const locks = new Set();
	const editorCalls = [];
	const post = Object.assign( { id: 1, status: 'draft', savedStatus: 'draft', password: '', edits: {} }, options.post || {} );

	const hooks = {
		addFilter: ( name, namespace, callback ) => {
			( filters[ name ] = filters[ name ] || [] ).push( callback );
		},
		addAction: ( name, namespace, callback ) => {
			( actions[ name ] = actions[ name ] || [] ).push( callback );
		}
	};
	if ( options.asyncFilters !== false ) {
		hooks.applyFiltersAsync = () => {};
	}

	const editorStore = {
		getCurrentPostId: () => post.id,
		getCurrentPostType: () => 'post',
		getCurrentPostAttribute: key => ( key === 'status' ? post.savedStatus : undefined ),
		getEditedPostAttribute: key => ( {
			status: post.status,
			title: 'We are hiring',
			excerpt: '',
			categories: [ 4 ],
			tags: [],
			author: post.author || 1,
			date: null,
			meta: {},
			password: post.password
		} )[ key ],
		getEditedPostContent: () => '<p>Hello</p>',
		getPermalink: () => 'https://example.test/?p=1',
		getPostEdits: () => post.edits,
		isEditedPostBeingScheduled: () => false,
		isEditedPostDateFloating: () => true,
		isSavingPost: () => false,
		isAutosavingPost: () => false
	};
	const coreStore = {
		getEditedEntityRecord: () => post.record || ( post.record = { content: '<p>Hello</p>' } ),
		getMedia: () => null
	};
	const select = name => ( name === 'core/editor' ? editorStore : coreStore );

	let renderPlugin;
	const wp = {
		plugins: { registerPlugin: ( name, settings ) => {
			renderPlugin = settings.render;
		} },
		editor: { PluginDocumentSettingPanel: 'Panel', PluginPrePublishPanel: options.prePublish ? 'PrePublishPanel' : undefined },
		components: Object.fromEntries( [ 'Button', 'Notice', 'SelectControl', 'TextareaControl', 'TextControl', 'ToggleControl', 'Spinner' ].map( name => [ name, name ] ) ),
		i18n: {
			__: value => value,
			_n: ( single, plural, count ) => ( count === 1 ? single : plural ),
			sprintf: ( format, ...args ) => {
				let index = 0;
				return format.replace( /%(\d+)\$[sd]|%[sd]/g, ( match, position ) => String( position ? args[ position - 1 ] : args[ index++ ] ) );
			}
		},
		element: renderer.element,
		hooks,
		data: {
			useSelect: callback => callback( select ),
			select,
			dispatch: () => ( {
				editPost: ( edit, editOptions ) => {
					editorCalls.push( { edit, options: editOptions } );
					Object.keys( edit ).forEach( key => {
						if ( edit[ key ] === undefined ) {
							delete post.edits[ key ];
						} else {
							post.edits[ key ] = edit[ key ];
						}
					} );
				},
				lockPostSaving: name => locks.add( name ),
				unlockPostSaving: name => locks.delete( name )
			} )
		},
		apiFetch: request => new Promise( ( resolve, reject ) => {
			pending.push( { request, resolve, reject } );
		} )
	};

	vm.runInNewContext( SOURCE, {
		window: { wp, pressqueuePostControls: Object.assign( { canAccess: true, canViewLog: true, schedules: { inherit: 'Use the rule', queue_end: 'Add to the end of the Buffer queue', explicit_time: 'Pick a date and time' }, images: { inherit: 'Use the rule', none: 'No image', post_media: 'Choose images' } }, options.config || {} ) },
		setTimeout: ( callback ) => {
			timers.set( ++nextTimer, callback );
			return nextTimer;
		},
		clearTimeout: ( id ) => {
			if ( id ) {
				timers.delete( id );
			}
		},
		Promise,
		Intl
	} );

	let tree;
	function render() {
		tree = renderer.render( { type: renderPlugin, props: {}, children: [] } );
		return tree;
	}

	function walk( node, predicate, found = [] ) {
		if ( ! node || typeof node !== 'object' ) {
			return found;
		}
		if ( Array.isArray( node ) ) {
			node.forEach( child => walk( child, predicate, found ) );
			return found;
		}
		if ( predicate( node ) ) {
			found.push( node );
		}
		( node.children || [] ).forEach( child => walk( child, predicate, found ) );
		return found;
	}

	function text( node ) {
		if ( node === null || node === undefined || node === false ) {
			return '';
		}
		if ( typeof node !== 'object' ) {
			return String( node );
		}
		if ( Array.isArray( node ) ) {
			return node.map( text ).join( '' );
		}
		return ( node.children || [] ).map( text ).join( '' );
	}

	async function settle() {
		for ( let i = 0; i < 3; i++ ) {
			await new Promise( resolve => setImmediate( resolve ) );
			render();
		}
	}

	async function runFilters( name, value, filterOptions ) {
		let result = value;
		for ( const callback of filters[ name ] || [] ) {
			result = await callback( result, filterOptions );
		}
		return result;
	}

	// Mirrors core/editor savePost(): preSavePost runs first and a throw aborts the save.
	const postRequests = [];
	async function savePost( saveOptions = {} ) {
		let edits = Object.assign( { id: post.id, title: 'We are hiring' }, post.edits );
		try {
			edits = await runFilters( 'editor.preSavePost', edits, saveOptions );
		} catch ( error ) {
			return { saved: false, error };
		}
		postRequests.push( { edits, options: saveOptions } );
		return { saved: true, edits };
	}

	render();
	return {
		post,
		pending,
		timers,
		filters,
		actions,
		locks,
		editorCalls,
		postRequests,
		render,
		settle,
		savePost,
		text: () => text( tree ),
		textOf: node => text( node ),
		find: ( type, label ) => walk( tree, node => node.type === type && ( label === undefined || node.props.label === label || text( node ).includes( label ) ) )[ 0 ],
		findAll: type => walk( tree, node => node.type === type ),
		flushTimers: () => {
			const run = Array.from( timers.values() );
			timers.clear();
			run.forEach( callback => callback() );
		},
		request: predicate => pending.find( item => predicate( item.request ) ),
		unmount: () => renderer.unmount()
	};
}

async function loaded( options = {}, response = controlsResponse() ) {
	const h = harness( options );
	assert.equal( h.pending[ 0 ].request.path, '/pressqueue/v1/posts/1/controls' );
	h.pending[ 0 ].resolve( response );
	await h.settle();
	return h;
}

function isPut( item ) {
	return item.request.method === 'PUT';
}

test( 'the panel shows one switch with a plain summary and no separate save button or publish lock', async () => {
	const h = await loaded();
	const toggle = h.find( 'ToggleControl', 'Share this post' );
	assert.equal( toggle.props.checked, true );
	assert.equal( h.find( 'Button', 'Save Controls' ), undefined );
	assert.equal( h.find( 'Button', 'Save sharing settings' ), undefined );
	assert.ok( h.text().includes( 'Test mode is on. Nothing is sent to Buffer yet.' ) );
	assert.equal( h.locks.size, 0 );

	h.flushTimers();
	const preview = h.request( request => request.path === '/pressqueue/v1/posts/1/preview' );
	assert.ok( preview, 'a debounced preview request is sent' );
	assert.equal( preview.request.data.editor_snapshot.post_status, 'publish' );
	assert.ok( Object.keys( preview.request.data.editor_snapshot.metadata ).length > 0, 'metadata stays a JSON object' );
	preview.resolve( previewResponse() );
	await h.settle();
	assert.ok( h.text().includes( 'When you publish, PressQueue sends it to:' ) );
	assert.ok( h.text().includes( 'Larkfield Bakery' ) );
	assert.ok( h.text().includes( 'End of the Buffer queue · with the featured image' ) );
	h.unmount();
} );

test( 'a stored exclusion starts switched off and opt-in sites explain the default', async () => {
	const response = controlsResponse( 'exclude' );
	response.editor.new_post_sharing = 'opt_in';
	response.editor.opt_in_hint = 'On this site, new posts start with sharing off.';
	const h = await loaded( {}, response );
	assert.equal( h.find( 'ToggleControl', 'Share this post' ).props.checked, false );
	assert.ok( h.text().includes( 'This post will not be shared. You can switch it on any time before publishing.' ) );
	assert.ok( h.text().includes( 'On this site, new posts start with sharing off.' ) );
	h.flushTimers();
	const preview = h.request( request => request.path.endsWith( '/preview' ) );
	assert.ok( preview, 'the post is still checked for "never share" rules while sharing is off' );
	assert.equal( preview.request.data.controls.policy, 'inherit', 'the check runs as if sharing were on' );
	preview.resolve( previewResponse() );
	await h.settle();
	assert.equal( h.find( 'ToggleControl', 'Share this post' ).props.checked, false );
	assert.equal( h.find( 'ToggleControl', 'Share this post' ).props.disabled, false );
	assert.ok( h.text().includes( 'This post will not be shared. You can switch it on any time before publishing.' ) );
	assert.equal( h.text().includes( 'PressQueue sends it to' ), false, 'no channel summary while sharing is off' );
	h.unmount();
} );

function keptOffResponse() {
	const response = previewResponse( [] );
	response.display.reason = 'The "never share" rule "Keep the private journal private" matches this post.';
	response.display.kept_off = 'Kept off social media by the "never share" rule "Keep the private journal private".';
	return response;
}

test( 'a matching "never share" rule locks the switch off without changing the stored choice', async () => {
	const h = await loaded( { prePublish: true } );
	h.flushTimers();
	// The panel and the pre-publish sidebar each check the post.
	const previews = h.pending.filter( item => item.request.path.endsWith( '/preview' ) );
	assert.equal( previews.length, 2 );
	previews.forEach( item => item.resolve( keptOffResponse() ) );
	await h.settle();

	const toggles = h.findAll( 'ToggleControl' );
	assert.equal( toggles.length, 2 );
	toggles.forEach( toggle => {
		assert.equal( toggle.props.checked, false );
		assert.equal( toggle.props.disabled, true );
		assert.equal( toggle.props.help, 'Kept off social media by the "never share" rule "Keep the private journal private".' );
	} );
	assert.equal( h.find( 'Button', 'Customize for this post' ), undefined, 'nothing to customize' );
	assert.equal( h.text().includes( 'Not shared when you publish.' ), false );
	assert.ok( h.text().includes( 'Test mode is on. Nothing is sent to Buffer yet.' ) );

	toggles[ 0 ].props.onChange( true );
	h.render();
	assert.equal( MARKER in h.post.edits, false, 'the stored choice is untouched' );
	assert.equal( ( await h.savePost( {} ) ).saved, true );
	assert.equal( h.pending.filter( isPut ).length, 0, 'nothing is stored' );

	// The post changes so the rule no longer matches.
	h.post.record = { content: '<p>Moved out of the journal</p>' };
	h.render();
	h.flushTimers();
	const next = h.pending.filter( item => item.request.path.endsWith( '/preview' ) ).slice( 2 );
	assert.equal( next.length, 2 );
	next.forEach( item => item.resolve( previewResponse() ) );
	await h.settle();
	h.findAll( 'ToggleControl' ).forEach( toggle => {
		assert.equal( toggle.props.checked, true );
		assert.equal( toggle.props.disabled, false );
		assert.equal( toggle.props.help, undefined );
	} );
	assert.ok( h.find( 'Button', 'Customize for this post' ) );
	assert.ok( h.text().includes( 'When you publish, PressQueue sends it to:' ) );
	h.unmount();
} );

test( 'a "never share" lock never outlives the post facts it was checked for', async () => {
	const h = await loaded();
	h.flushTimers();
	h.request( request => request.path.endsWith( '/preview' ) ).resolve( keptOffResponse() );
	await h.settle();
	assert.equal( h.find( 'ToggleControl' ).props.disabled, true );

	// The category changes. Until the new answer arrives, the switch shows the stored choice again.
	h.post.record = { content: '<p>Moved out of the journal</p>' };
	h.render();
	h.render(); // The effect's state change re-renders right after the commit in React.
	assert.equal( h.find( 'ToggleControl' ).props.checked, true, 'stored choice is visible while checking' );
	assert.equal( h.find( 'ToggleControl' ).props.disabled, false );

	// A failed check must not bring the old lock back either.
	h.flushTimers();
	const next = h.pending.filter( item => item.request.path.endsWith( '/preview' ) ).slice( 1 );
	assert.equal( next.length, 1 );
	next[ 0 ].reject( { message: 'Server unavailable' } );
	await h.settle();
	assert.equal( h.find( 'ToggleControl' ).props.disabled, false );
	assert.equal( h.find( 'ToggleControl' ).props.checked, true );
	assert.ok( h.text().includes( 'PressQueue could not check where this post goes: Server unavailable' ) );
	h.unmount();
} );

test( 'changing the author checks the post again and never keeps an old lock or "not shared" answer', async () => {
	const h = await loaded();
	h.flushTimers();
	h.request( request => request.path.endsWith( '/preview' ) ).resolve( keptOffResponse() );
	await h.settle();
	assert.equal( h.find( 'ToggleControl' ).props.disabled, true );

	h.post.author = 7;
	h.render();
	h.render();
	assert.equal( h.find( 'ToggleControl' ).props.disabled, false, 'author change releases the lock' );
	assert.ok( h.text().includes( 'Checking where this post goes' ), 'no stale answer while checking' );

	h.flushTimers();
	const next = h.pending.filter( item => item.request.path.endsWith( '/preview' ) ).slice( 1 );
	assert.equal( next.length, 1, 'a new check runs for the new author' );
	assert.equal( next[ 0 ].request.data.editor_snapshot.author_id, 7 );
	next[ 0 ].resolve( previewResponse() );
	await h.settle();
	assert.ok( h.text().includes( 'When you publish, PressQueue' ) );
	h.unmount();
} );

test( 'a channel restriction stays until the writer resets it', async () => {
	const restricted = controlsResponse();
	restricted.controls.channels = { mode: 'override', channel_public_ids: [ 'CH1' ] };
	const h = await loaded( {}, restricted );
	const boxes = () => h.findAll( 'input' ).filter( node => node.props.type === 'checkbox' );
	assert.equal( boxes().length, 2, 'Customize opens by itself when the post has a restriction' );
	// Tick the second channel too: the restriction stays, now naming both.
	boxes()[ 1 ].props.onChange( { target: { checked: true } } );
	h.render();
	assert.ok( h.find( 'Button', 'Use the channels from the rule again' ) );
	h.find( 'Button', 'Use the channels from the rule again' ).props.onClick();
	h.render();
	const save = h.savePost( {} );
	await new Promise( resolve => setImmediate( resolve ) );
	assert.deepEqual( plain( h.pending.find( isPut ).request.data.controls.channels ), { mode: 'inherit' } );
	h.pending.find( isPut ).resolve( controlsResponse() );
	await save;
	await h.settle();
	h.unmount();
} );

test( 'a stored exclusion that a "never share" rule also covers stays stored as it is', async () => {
	const h = await loaded( {}, controlsResponse( 'exclude' ) );
	h.flushTimers();
	const preview = h.request( request => request.path.endsWith( '/preview' ) );
	assert.equal( preview.request.data.controls.policy, 'inherit' );
	preview.resolve( keptOffResponse() );
	await h.settle();
	const toggle = h.find( 'ToggleControl', 'Share this post' );
	assert.equal( toggle.props.checked, false );
	assert.equal( toggle.props.disabled, true );
	assert.equal( h.text().includes( 'This post will not be shared.' ), false );
	assert.equal( MARKER in h.post.edits, false );
	h.unmount();
} );

test( 'a half-filled date and time does not block saving while sharing is off', async () => {
	const h = await loaded();
	h.find( 'Button', 'Customize for this post' ).props.onClick();
	h.render();
	h.find( 'SelectControl', 'Timing' ).props.onChange( 'explicit_time' );
	h.render();
	h.find( 'TextControl', 'Date' ).props.onChange( '2026-10-20' );
	h.render();
	h.find( 'ToggleControl', 'Share this post' ).props.onChange( false );
	h.render();

	const saving = h.savePost( {} );
	await new Promise( resolve => setImmediate( resolve ) );
	const put = h.pending.find( isPut );
	assert.ok( put, 'the settings are saved' );
	assert.equal( put.request.data.controls.policy, 'exclude' );
	assert.deepEqual( plain( put.request.data.controls.schedule ), { mode: 'inherit' } );
	put.resolve( controlsResponse( 'exclude' ) );
	assert.equal( ( await saving ).saved, true );
	assert.equal( h.postRequests.length, 1 );
	h.unmount();
} );

test( 'changing the switch marks the post dirty with a transient edit and switching back clears it', async () => {
	const h = await loaded();
	h.find( 'ToggleControl', 'Share this post' ).props.onChange( false );
	h.render();
	assert.equal( h.post.edits[ MARKER ], true );
	assert.deepEqual( plain( h.editorCalls[ 0 ].options ), { undoIgnore: true } );
	h.find( 'ToggleControl', 'Share this post' ).props.onChange( true );
	h.render();
	assert.equal( MARKER in h.post.edits, false );
	h.unmount();
} );

test( 'saving the post stores dirty sharing settings first and never sends the marker', async () => {
	const h = await loaded();
	h.find( 'ToggleControl', 'Share this post' ).props.onChange( false );
	h.render();

	const saving = h.savePost( {} );
	await new Promise( resolve => setImmediate( resolve ) );
	const put = h.pending.find( isPut );
	assert.ok( put, 'controls are PUT before the post is saved' );
	assert.equal( h.postRequests.length, 0, 'the post waits for the controls' );
	assert.equal( put.request.data.base_control_bundle_hash, 'hash-inherit' );
	assert.equal( put.request.data.controls.policy, 'exclude' );

	put.resolve( controlsResponse( 'exclude' ) );
	const result = await saving;
	assert.equal( result.saved, true );
	assert.equal( MARKER in result.edits, false );
	assert.equal( MARKER in h.post.edits, false, 'the post is clean again' );
	await h.settle();
	assert.equal( h.find( 'ToggleControl', 'Share this post' ).props.checked, false );

	// A second save has nothing to store.
	const again = await h.savePost( {} );
	assert.equal( again.saved, true );
	assert.equal( h.pending.filter( isPut ).length, 1 );
	h.unmount();
} );

test( 'a failing sharing save aborts the post save so an unsaved off switch is never published', async () => {
	const h = await loaded();
	h.find( 'ToggleControl', 'Share this post' ).props.onChange( false );
	h.render();

	const publishing = h.savePost( {} );
	await new Promise( resolve => setImmediate( resolve ) );
	h.pending.find( isPut ).reject( { code: 'conflict', message: 'PressQueue controls changed before replacement.' } );
	const result = await publishing;

	assert.equal( result.saved, false );
	assert.equal( h.postRequests.length, 0, 'the post was not sent' );
	assert.match( result.error.message, /changed in another window/ );
	await h.settle();
	assert.equal( h.find( 'ToggleControl', 'Share this post' ).props.checked, false, 'the unsaved choice is kept' );
	assert.equal( h.post.edits[ MARKER ], true, 'the post stays dirty' );
	const notice = h.find( 'Notice' );
	assert.equal( notice.props.status, 'error' );
	assert.equal( notice.props.actions[ 0 ].label, 'Load the latest settings' );
	h.unmount();
} );

test( 'network failures and validation problems also abort the post save', async () => {
	const h = await loaded();
	h.find( 'ToggleControl', 'Share this post' ).props.onChange( false );
	h.render();
	const first = h.savePost( {} );
	await new Promise( resolve => setImmediate( resolve ) );
	h.pending.find( isPut ).reject( { code: 'fetch_error', message: 'You are probably offline.' } );
	assert.equal( ( await first ).saved, false );

	// An explicit time without a date fails before any request is made.
	await h.settle();
	const controls = h.find( 'ToggleControl', 'Share this post' );
	controls.props.onChange( true );
	h.render();
	h.find( 'Button', 'Customize for this post' ).props.onClick();
	h.render();
	h.find( 'SelectControl', 'Timing' ).props.onChange( 'explicit_time' );
	h.render();
	const puts = h.pending.filter( isPut ).length;
	const second = await h.savePost( {} );
	assert.equal( second.saved, false );
	assert.match( second.error.message, /Pick a date and a time/ );
	assert.equal( h.pending.filter( isPut ).length, puts );
	assert.equal( h.postRequests.length, 0 );
	h.unmount();
} );

test( 'autosaves skip the sharing save and strip the marker', async () => {
	const h = await loaded();
	h.find( 'ToggleControl', 'Share this post' ).props.onChange( false );
	h.render();
	const result = await h.savePost( { isAutosave: true } );
	assert.equal( result.saved, true );
	assert.equal( MARKER in result.edits, false );
	assert.equal( h.pending.filter( isPut ).length, 0 );
	assert.equal( h.post.edits[ MARKER ], true, 'the choice is still unsaved' );
	h.unmount();
} );

test( 'concurrent saves send one request and reuse the new base hash', async () => {
	const h = await loaded();
	h.find( 'ToggleControl', 'Share this post' ).props.onChange( false );
	h.render();
	const first = h.savePost( {} );
	const second = h.savePost( {} );
	await new Promise( resolve => setImmediate( resolve ) );
	assert.equal( h.pending.filter( isPut ).length, 1 );
	h.pending.find( isPut ).resolve( controlsResponse( 'exclude' ) );
	assert.equal( ( await first ).saved, true );
	assert.equal( ( await second ).saved, true );
	assert.equal( h.pending.filter( isPut ).length, 1 );
	h.unmount();
} );

test( 'preview responses that arrive late are ignored', async () => {
	const h = await loaded();
	h.flushTimers();
	const older = h.request( request => request.path.endsWith( '/preview' ) );
	h.find( 'Button', 'Customize for this post' ).props.onClick();
	h.render();
	h.find( 'TextareaControl', 'Message' ).props.onChange( 'Come and work with us' );
	h.render();
	h.flushTimers();
	const newer = h.pending.filter( item => item.request.path.endsWith( '/preview' ) )[ 1 ];
	assert.ok( newer );
	assert.equal( newer.request.data.controls.message.value, 'Come and work with us' );
	newer.resolve( previewResponse( [ 'Newer channel' ] ) );
	await h.settle();
	older.resolve( previewResponse( [ 'Older channel' ] ) );
	await h.settle();
	assert.ok( h.text().includes( 'Newer channel' ) );
	assert.equal( h.text().includes( 'Older channel' ), false );
	h.unmount();
} );

test( 'the message count includes the link and warns when the text is too long', async () => {
	const h = await loaded();
	h.flushTimers();
	h.request( request => request.path.endsWith( '/preview' ) ).resolve( previewResponse() );
	await h.settle();
	h.find( 'Button', 'Customize for this post' ).props.onClick();
	h.render();
	const help = () => h.textOf( h.find( 'TextareaControl', 'Message' ).props.help );
	assert.equal( h.find( 'TextareaControl', 'Message' ).props.placeholder, 'We are hiring' );
	assert.match( help(), /45 of 280 characters, including the link\./ );
	h.find( 'TextareaControl', 'Message' ).props.onChange( 'x'.repeat( 260 ) );
	h.render();
	assert.match( help(), /12 characters too long for X\. The link counts too\./ );
	h.unmount();
} );

test( 'leaving a channel out stores a restrictive override and ticking it again inherits', async () => {
	const h = await loaded();
	h.find( 'Button', 'Customize for this post' ).props.onClick();
	h.render();
	const boxes = h.findAll( 'input' ).filter( node => node.props.type === 'checkbox' );
	assert.equal( boxes.length, 2 );
	boxes[ 1 ].props.onChange( { target: { checked: false } } );
	h.render();
	const put = h.savePost( {} );
	await new Promise( resolve => setImmediate( resolve ) );
	assert.deepEqual( plain( h.pending.find( isPut ).request.data.controls.channels ), { mode: 'override', channel_public_ids: [ 'CH1' ] } );
	h.pending.find( isPut ).resolve( controlsResponse() );
	await put;
	await h.settle();
	h.unmount();
} );

test( 'published posts show what happened per channel with a link to Activity', async () => {
	const h = await loaded( { post: { status: 'publish', savedStatus: 'publish' } } );
	const activity = h.request( request => request.path === '/pressqueue/v1/posts/1/activity' );
	assert.ok( activity );
	activity.resolve( { activity: { url: 'admin.php?page=pressqueue-deliveries&post_id=1', not_shared: '', not_shared_title: '', items: [
		{ public_id: 'CH1', service: 'linkedin', service_name: 'LinkedIn', monogram: 'in', name: 'Larkfield Bakery', state: 'accepted', label: 'Sent to Buffer', tone: 'ok', help: '', time: '5 mins ago', time_full: '' }
	] } } );
	await h.settle();
	assert.ok( h.text().includes( 'Sent to Buffer' ) );
	assert.ok( h.text().includes( '5 mins ago' ) );
	assert.equal( h.find( 'a', 'See in Activity' ).props.href, 'admin.php?page=pressqueue-deliveries&post_id=1' );
	h.unmount();
} );

test( 'a failed share shows its reason under the status', async () => {
	const h = await loaded( { post: { status: 'publish', savedStatus: 'publish' } } );
	h.request( request => request.path === '/pressqueue/v1/posts/1/activity' ).resolve( { activity: { url: '', not_shared: '', not_shared_title: '', items: [
		{ public_id: 'CH1', service: 'linkedin', service_name: 'LinkedIn', monogram: 'in', name: 'Larkfield Bakery', state: 'failed', label: 'Failed', tone: 'bad', help: '', reason: 'Buffer said the API key is no longer valid.', time: '5 mins ago', time_full: '' },
		{ public_id: 'CH2', service: 'twitter', service_name: 'X', monogram: 'X', name: '@larkfieldbakes', state: 'accepted', label: 'Sent to Buffer', tone: 'ok', help: '', reason: '', time: '5 mins ago', time_full: '' }
	] } } );
	await h.settle();
	const reasons = h.findAll( 'span' ).filter( node => node.props.className === 'pq-ed-small pq-ed-activity-reason' );
	assert.equal( reasons.length, 1 );
	assert.equal( h.textOf( reasons[ 0 ] ), 'Buffer said the API key is no longer valid.' );
	h.unmount();
} );

test( 'users without access never see the panel', async () => {
	const hidden = harness( { config: { canAccess: false } } );
	assert.equal( hidden.pending.length, 0 );
	assert.equal( hidden.find( 'Panel' ), undefined );
	hidden.unmount();

	const forbidden = harness();
	forbidden.pending[ 0 ].reject( { code: 'forbidden', data: { status: 403 } } );
	await forbidden.settle();
	assert.equal( forbidden.find( 'Panel' ), undefined );
	assert.equal( await forbidden.savePost( {} ).then( result => result.saved ), true );
	forbidden.unmount();
} );

test( 'a late response from the previous post cannot overwrite the new post controls', async () => {
	const h = harness();
	h.post.id = 2;
	h.render();
	h.pending[ 1 ].resolve( controlsResponse( 'exclude' ) );
	await h.settle();
	h.pending[ 0 ].resolve( controlsResponse( 'include' ) );
	await h.settle();
	assert.equal( h.find( 'ToggleControl', 'Share this post' ).props.checked, false );
	h.unmount();
} );

test( 'an explicit include stays include when switched off and on again', async () => {
	const h = await loaded( {}, controlsResponse( 'include' ) );
	h.find( 'ToggleControl', 'Share this post' ).props.onChange( false );
	h.render();
	h.find( 'ToggleControl', 'Share this post' ).props.onChange( true );
	h.render();
	assert.equal( MARKER in h.post.edits, false );
	h.unmount();
} );

test( 'without async editor filters, unsaved settings lock saving until stored', async () => {
	const h = await loaded( { asyncFilters: false } );
	assert.equal( h.filters[ 'editor.preSavePost' ], undefined );
	h.find( 'ToggleControl', 'Share this post' ).props.onChange( false );
	h.render();
	assert.equal( h.locks.has( 'pressqueue-controls' ), true );
	h.find( 'Button', 'Save sharing settings' ).props.onClick();
	await new Promise( resolve => setImmediate( resolve ) );
	h.pending.find( isPut ).resolve( controlsResponse( 'exclude' ) );
	await h.settle();
	assert.equal( h.locks.size, 0 );
	h.unmount();
} );

test( 'the pre-publish sidebar repeats the switch and summary', async () => {
	const h = await loaded( { prePublish: true } );
	assert.ok( h.find( 'PrePublishPanel' ) );
	assert.equal( h.findAll( 'ToggleControl' ).length, 2 );
	h.unmount();
} );
