'use strict';

const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const { test } = require( 'node:test' );
const vm = require( 'node:vm' );

// Run the actual registered panel with a small hook driver. API responses remain
// pending until each test resolves them, so races and failed saves are observable.
function panelHarness() {
	const hooks = [];
	const effects = [];
	const pending = [];
	const locks = new Set();
	let cursor = 0;
	let renderPanel;
	let postId = 1;
	let tree;
	function changed( a, b ) {
		return ! a || a.length !== b.length || a.some( ( value, i ) => value !== b[ i ] );
	}
	const wp = {
		plugins: { registerPlugin: ( name, options ) => { renderPanel = options.render; } },
		editor: { PluginDocumentSettingPanel: 'Panel' },
		components: Object.fromEntries( [ 'Button', 'CheckboxControl', 'Notice', 'SelectControl', 'TextareaControl', 'TextControl' ].map( name => [ name, name ] ) ),
		i18n: { __: value => value },
		element: {
			createElement: ( type, props, ...children ) => ( { type, props: props || {}, children: children.flat() } ),
			useState: initial => {
				const index = cursor++;
				if ( ! hooks[ index ] ) { hooks[ index ] = { value: initial }; }
				return [ hooks[ index ].value, value => {
					hooks[ index ].value = typeof value === 'function' ? value( hooks[ index ].value ) : value;
				} ];
			},
			useRef: initial => {
				const index = cursor++;
				if ( ! hooks[ index ] ) { hooks[ index ] = { current: initial }; }
				return hooks[ index ];
			},
			useEffect: ( callback, dependencies ) => {
				const index = cursor++;
				if ( changed( hooks[ index ] && hooks[ index ].dependencies, dependencies ) ) {
					effects.push( () => {
						if ( hooks[ index ] && hooks[ index ].cleanup ) { hooks[ index ].cleanup(); }
						hooks[ index ] = { dependencies, cleanup: callback() };
					} );
				}
			}
		},
		data: {
			useSelect: callback => callback( () => ( {
				getCurrentPostId: () => postId,
				getCurrentPostType: () => 'post',
				getEditedPostAttribute: () => '',
				getEditedPostAttributes: () => ( {} ),
				getEditedPostContent: () => '',
				getPermalink: () => 'https://example.test/post'
			} ) ),
			dispatch: () => ( {
				lockPostSaving: name => locks.add( name ),
				unlockPostSaving: name => locks.delete( name )
			} )
		},
		apiFetch: options => new Promise( ( resolve, reject ) => { pending.push( { options, resolve, reject } ); } )
	};
	vm.runInNewContext( readFileSync( require.resolve( '../../assets/editor/post-controls.js' ), 'utf8' ), { window: { wp } } );
	function render() {
		cursor = 0;
		tree = renderPanel();
		while ( effects.length ) { effects.shift()(); }
		return tree;
	}
	function find( type, label ) {
		function walk( item ) {
			if ( ! item || typeof item !== 'object' ) { return undefined; }
			if ( item.type === type && ( item.props.label === label || item.children.includes( label ) ) ) { return item; }
			return ( item.children || [] ).map( walk ).find( Boolean );
		}
		return walk( tree );
	}
	async function settle() {
		await new Promise( resolve => setImmediate( resolve ) );
		render();
	}
	render();
	return { render, find, settle, pending, locks,
		changePost: value => { postId = value; render(); },
		unmount: () => hooks.forEach( hook => { if ( hook && hook.cleanup ) { hook.cleanup(); } } )
	};
}

function response( policy = 'inherit' ) {
	return {
		control_bundle_hash: 'hash-' + policy,
		controls: { schema_version: 2, policy, channels: { mode: 'inherit' }, message: { mode: 'inherit' }, schedule: { mode: 'inherit' }, media: { mode: 'inherit' } },
		effective_facts: {}
	};
}

async function loadedPanel() {
	const h = panelHarness();
	assert.equal( h.find( 'Button', 'Save Controls' ).props.disabled, true );
	h.pending[ 0 ].resolve( response() );
	await h.settle();
	return h;
}

test( 'unsaved exclusion blocks post saving until controls are saved; duplicate requests are ignored', async () => {
	const h = await loadedPanel();
	assert.equal( h.locks.size, 0 );
	h.find( 'SelectControl', 'Policy' ).props.onChange( 'exclude' );
	h.render();
	assert.equal( h.locks.has( 'pressqueue-controls' ), true );
	const save = h.find( 'Button', 'Save Controls' ).props.onClick;
	save(); save();
	assert.equal( h.pending.length, 2 );
	assert.equal( h.pending[ 1 ].options.data.controls.policy, 'exclude' );
	h.render();
	assert.equal( h.find( 'SelectControl', 'Policy' ).props.disabled, true );
	h.pending[ 1 ].resolve( response( 'exclude' ) );
	await h.settle();
	assert.equal( h.locks.size, 0 );
	h.unmount();
} );

test( 'failed saves retain the unsaved controls and the publishing lock', async () => {
	const h = await loadedPanel();
	h.find( 'SelectControl', 'Policy' ).props.onChange( 'exclude' ); h.render();
	h.find( 'Button', 'Save Controls' ).props.onClick();
	h.pending[ 1 ].reject( new Error( 'Conflict' ) );
	await h.settle();
	assert.equal( h.find( 'SelectControl', 'Policy' ).props.value, 'exclude' );
	assert.equal( h.locks.has( 'pressqueue-controls' ), true );
	assert.equal( h.find( 'Notice', 'Conflict' ).props.status, 'error' );
	h.unmount();
	assert.equal( h.locks.size, 0 );
} );

test( 'changing controls clears the previous preview and reverting changes releases the lock', async () => {
	const h = await loadedPanel();
	h.find( 'Button', 'Preview' ).props.onClick();
	h.pending[ 1 ].resolve( { summary: 'local preview' } ); await h.settle();
	assert.ok( h.find( 'Notice', 'Preview ready.' ) );
	h.find( 'SelectControl', 'Policy' ).props.onChange( 'exclude' ); h.render();
	assert.equal( h.find( 'Notice', 'Preview ready.' ), undefined );
	h.find( 'SelectControl', 'Policy' ).props.onChange( 'inherit' ); h.render();
	assert.equal( h.locks.size, 0 );
	h.unmount();
} );

test( 'a late response from the previous post cannot overwrite the new post controls', async () => {
	const h = panelHarness();
	h.changePost( 2 );
	h.pending[ 1 ].resolve( response( 'exclude' ) ); await h.settle();
	h.pending[ 0 ].resolve( response( 'include' ) ); await h.settle();
	assert.equal( h.find( 'SelectControl', 'Policy' ).props.value, 'exclude' );
	assert.equal( h.locks.size, 0 );
	h.unmount();
} );
