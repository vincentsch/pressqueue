( function ( wp ) {
	'use strict';

	var __ = wp && wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( text ) {
		return text;
	};

	function defaultCondition() {
		return {
			type: 'condition',
			condition: 'post_type',
			operator: 'equals',
			value: 'post'
		};
	}

	function defaultGroup() {
		return {
			type: 'group',
			mode: 'ALL',
			children: [ defaultCondition() ]
		};
	}

	function rootDocument() {
		var group = defaultGroup();
		group.schema_version = 1;
		return group;
	}

	function parseTextarea( textarea, fallbackTree ) {
		if ( ! textarea.value || ! textarea.value.trim() ) {
			return {
				error: null,
				rawInvalid: false,
				tree: rootDocument()
			};
		}

		try {
			var parsed = JSON.parse( textarea.value || '{}' );
			if ( ! parsed || parsed.type !== 'group' ) {
				return {
					error: __( 'Condition JSON must be an object with a group root.', 'pressqueue' ),
					rawInvalid: true,
					tree: fallbackTree || rootDocument()
				};
			}
			normalizeGroupAliases( parsed );
			return {
				error: null,
				rawInvalid: false,
				tree: parsed
			};
		} catch ( error ) {
			return {
				error: __( 'Condition JSON is invalid:', 'pressqueue' ) + ' ' + error.message,
				rawInvalid: true,
				tree: fallbackTree || rootDocument()
			};
		}
	}

	function normalizeGroupAliases( node ) {
		if ( ! node || node.type !== 'group' ) {
			return;
		}
		if ( ! node.mode && node.operator ) {
			node.mode = node.operator;
			delete node.operator;
		}
		( node.children || [] ).forEach( normalizeGroupAliases );
	}

	function writeTextarea( textarea, tree ) {
		textarea.value = JSON.stringify( tree, null, 2 );
	}

	function getNode( tree, path ) {
		var node = tree;
		path.forEach( function ( index ) {
			node = node.children[ index ];
		} );
		return node;
	}

	function getParentInfo( tree, path ) {
		if ( path.length === 0 ) {
			return null;
		}
		var parentPath = path.slice( 0, -1 );
		return {
			parent: getNode( tree, parentPath ),
			index: path[ path.length - 1 ]
		};
	}

	function pathFromString( value ) {
		if ( ! value ) {
			return [];
		}
		return value.split( '.' ).map( function ( item ) {
			return parseInt( item, 10 );
		} );
	}

	function pathToString( path ) {
		return path.join( '.' );
	}

	function renderBuilder( container, textarea, state ) {
		var treeHost = container.querySelector( '.pressqueue-condition-builder__tree' );
		treeHost.innerHTML = '';
		state.controlIndex = 0;
		renderNode( treeHost, state.tree, [], state );
		renderError( container, state.error );
		var selected = treeHost.querySelector( '[data-path="' + pathToString( state.selectedPath ) + '"]' );
		if ( selected ) {
			selected.focus();
		}
		if ( ! state.rawInvalid ) {
			writeTextarea( textarea, state.tree );
		}
	}

	function renderError( container, message ) {
		var error = container.querySelector( '.pressqueue-condition-builder__error' );
		if ( ! error ) {
			error = document.createElement( 'p' );
			error.className = 'pressqueue-condition-builder__error notice notice-error';
			error.setAttribute( 'role', 'alert' );
			var treeHost = container.querySelector( '.pressqueue-condition-builder__tree' );
			container.insertBefore( error, treeHost );
		}

		error.textContent = message || '';
		error.hidden = ! message;
	}

	function markTreeChanged( state ) {
		state.error = null;
		state.rawInvalid = false;
	}

	function renderNode( host, node, path, state ) {
		var row = document.createElement( 'li' );
		row.setAttribute( 'tabindex', '0' );
		row.setAttribute( 'data-path', pathToString( path ) );
		row.className = pathToString( path ) === pathToString( state.selectedPath ) ? 'is-selected' : '';
		row.addEventListener( 'focus', function () {
			state.selectedPath = path.slice();
		} );

		if ( node.type === 'group' ) {
			var groupId = nextControlId( state, path, 'mode' );
			var label = controlLabel( groupId, __( 'Group mode', 'pressqueue' ) );
			row.appendChild( label );
			var select = document.createElement( 'select' );
			select.id = groupId;
			[ 'ALL', 'ANY' ].forEach( function ( mode ) {
				var option = document.createElement( 'option' );
				option.value = mode;
				option.textContent = __( mode, 'pressqueue' );
				option.selected = ( node.mode || node.operator ) === mode;
				select.appendChild( option );
			} );
			select.addEventListener( 'change', function () {
				node.mode = select.value;
				delete node.operator;
				markTreeChanged( state );
				renderBuilder( state.container, state.textarea, state );
			} );
			row.appendChild( select );
		} else {
			renderConditionControls( row, node, state );
		}

		host.appendChild( row );

		if ( node.type === 'group' && Array.isArray( node.children ) ) {
			var children = document.createElement( 'ul' );
			children.className = 'pressqueue-condition-builder__children';
			node.children.forEach( function ( child, index ) {
				renderNode( children, child, path.concat( index ), state );
			} );
			row.appendChild( children );
		}
	}

	function renderConditionControls( row, node, state ) {
		var path = pathFromString( row.getAttribute( 'data-path' ) || '' );
		var conditionId = nextControlId( state, path, 'condition' );
		var operatorId = nextControlId( state, path, 'operator' );
		var valueId = nextControlId( state, path, 'value' );
		var condition = document.createElement( 'select' );
		condition.id = conditionId;
		[ 'post_type', 'post_status', 'author', 'taxonomy', 'metadata', 'post_policy' ].forEach( function ( name ) {
			var option = document.createElement( 'option' );
			option.value = name;
			option.textContent = labelForValue( name );
			option.selected = node.condition === name;
			condition.appendChild( option );
		} );
		condition.addEventListener( 'change', function () {
			node.condition = condition.value;
			node.operator = defaultOperator( node.condition );
			node.value = defaultValue( node.condition );
			markTreeChanged( state );
			renderBuilder( state.container, state.textarea, state );
		} );

		var operator = document.createElement( 'select' );
		operator.id = operatorId;
		operatorOptions( node.condition ).forEach( function ( name ) {
			var option = document.createElement( 'option' );
			option.value = name;
			option.textContent = labelForValue( name );
			option.selected = node.operator === name;
			operator.appendChild( option );
		} );
		operator.addEventListener( 'change', function () {
			node.operator = operator.value;
			if ( node.condition === 'metadata' && ( node.operator === 'exists' || node.operator === 'not_exists' ) ) {
				node.value = { key: metadataKey( node.value ) };
			}
			markTreeChanged( state );
			renderBuilder( state.container, state.textarea, state );
		} );

		var value = document.createElement( 'input' );
		value.id = valueId;
		value.type = 'text';
		value.value = JSON.stringify( node.value );
		value.addEventListener( 'change', function () {
			try {
				node.value = JSON.parse( value.value );
			} catch ( error ) {
				node.value = value.value;
			}
			markTreeChanged( state );
			renderBuilder( state.container, state.textarea, state );
		} );

		row.appendChild( controlLabel( conditionId, __( 'Condition field', 'pressqueue' ) ) );
		row.appendChild( condition );
		row.appendChild( document.createTextNode( ' ' ) );
		row.appendChild( controlLabel( operatorId, __( 'Condition operator', 'pressqueue' ) ) );
		row.appendChild( operator );
		row.appendChild( document.createTextNode( ' ' ) );
		row.appendChild( controlLabel( valueId, __( 'Condition value', 'pressqueue' ) ) );
		row.appendChild( value );
	}

	function nextControlId( state, path, name ) {
		state.controlIndex += 1;
		return 'pressqueue-condition-' + state.instanceId + '-' + ( path.length ? path.join( '-' ) : 'root' ) + '-' + name + '-' + state.controlIndex;
	}

	function controlLabel( id, text ) {
		var label = document.createElement( 'label' );
		label.className = 'screen-reader-text';
		label.setAttribute( 'for', id );
		label.textContent = text;
		return label;
	}

	function labelForValue( value ) {
		var labels = {
			ALL: __( 'ALL', 'pressqueue' ),
			ANY: __( 'ANY', 'pressqueue' ),
			author: __( 'Author', 'pressqueue' ),
			equals: __( 'Equals', 'pressqueue' ),
			exists: __( 'Exists', 'pressqueue' ),
			has_all: __( 'Has all', 'pressqueue' ),
			has_any: __( 'Has any', 'pressqueue' ),
			has_none: __( 'Has none', 'pressqueue' ),
			in: __( 'In', 'pressqueue' ),
			metadata: __( 'Metadata', 'pressqueue' ),
			not_equals: __( 'Does not equal', 'pressqueue' ),
			not_exists: __( 'Does not exist', 'pressqueue' ),
			post_policy: __( 'Post policy', 'pressqueue' ),
			post_status: __( 'Post status', 'pressqueue' ),
			post_type: __( 'Post type', 'pressqueue' ),
			taxonomy: __( 'Taxonomy', 'pressqueue' )
		};

		return labels[ value ] || value;
	}

	function operatorOptions( condition ) {
		if ( condition === 'taxonomy' ) {
			return [ 'has_any', 'has_all', 'has_none' ];
		}
		if ( condition === 'metadata' ) {
			return [ 'equals', 'not_equals', 'exists', 'not_exists' ];
		}
		if ( condition === 'post_policy' ) {
			return [ 'equals' ];
		}
		return [ 'equals', 'in' ];
	}

	function defaultOperator( condition ) {
		return operatorOptions( condition )[ 0 ];
	}

	function defaultValue( condition ) {
		if ( condition === 'taxonomy' ) {
			return { taxonomy: 'category', terms: [ 1 ], include_ancestors: true };
		}
		if ( condition === 'metadata' ) {
			return { key: 'pressqueue_rating', value: { type: 'number', value: 5 } };
		}
		if ( condition === 'author' ) {
			return 1;
		}
		if ( condition === 'post_policy' ) {
			return 'inherit';
		}
		if ( condition === 'post_status' ) {
			return 'publish';
		}
		return 'post';
	}

	function metadataKey( value ) {
		return value && typeof value === 'object' && value.key ? value.key : 'pressqueue_rating';
	}

	function applyAction( action, state ) {
		var selected = getNode( state.tree, state.selectedPath );
		var info = getParentInfo( state.tree, state.selectedPath );

		if ( action === 'add-condition' ) {
			if ( selected.type === 'group' ) {
				selected.children.push( defaultCondition() );
				state.selectedPath = state.selectedPath.concat( selected.children.length - 1 );
				markTreeChanged( state );
			}
			return;
		}

		if ( action === 'add-group' ) {
			if ( selected.type === 'group' ) {
				selected.children.push( defaultGroup() );
				state.selectedPath = state.selectedPath.concat( selected.children.length - 1 );
				markTreeChanged( state );
			}
			return;
		}

		if ( ! info ) {
			return;
		}

		if ( action === 'delete' && info.parent.children.length > 1 ) {
			info.parent.children.splice( info.index, 1 );
			state.selectedPath = state.selectedPath.slice( 0, -1 ).concat( Math.max( 0, info.index - 1 ) );
			markTreeChanged( state );
			return;
		}

		if ( action === 'move-up' && info.index > 0 ) {
			info.parent.children.splice( info.index, 1 );
			info.parent.children.splice( info.index - 1, 0, selected );
			state.selectedPath[ state.selectedPath.length - 1 ] = info.index - 1;
			markTreeChanged( state );
			return;
		}

		if ( action === 'move-down' && info.index < info.parent.children.length - 1 ) {
			info.parent.children.splice( info.index, 1 );
			info.parent.children.splice( info.index + 1, 0, selected );
			state.selectedPath[ state.selectedPath.length - 1 ] = info.index + 1;
			markTreeChanged( state );
			return;
		}

		if ( action === 'indent' && info.index > 0 ) {
			var previous = info.parent.children[ info.index - 1 ];
			if ( previous.type === 'group' ) {
				info.parent.children.splice( info.index, 1 );
				previous.children.push( selected );
				state.selectedPath = state.selectedPath.slice( 0, -1 ).concat( info.index - 1, previous.children.length - 1 );
				markTreeChanged( state );
			}
			return;
		}

		if ( action === 'outdent' ) {
			var grandInfo = getParentInfo( state.tree, state.selectedPath.slice( 0, -1 ) );
			if ( grandInfo ) {
				info.parent.children.splice( info.index, 1 );
				grandInfo.parent.children.splice( grandInfo.index + 1, 0, selected );
				state.selectedPath = state.selectedPath.slice( 0, -2 ).concat( grandInfo.index + 1 );
				markTreeChanged( state );
			}
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.pressqueue-condition-builder' ).forEach( function ( container, index ) {
			var textarea = document.getElementById( container.getAttribute( 'data-target' ) );
			if ( ! textarea ) {
				return;
			}

			var parsed = parseTextarea( textarea, rootDocument() );
			var state = {
				container: container,
				controlIndex: 0,
				error: parsed.error,
				instanceId: index + 1,
				rawInvalid: parsed.rawInvalid,
				selectedPath: [],
				textarea: textarea,
				tree: parsed.tree
			};

			container.addEventListener( 'click', function ( event ) {
				var target = event.target;
				if ( ! target || ! target.getAttribute ) {
					return;
				}
				var action = target.getAttribute( 'data-pressqueue-condition-action' );
				if ( ! action || action === 'set-mode' ) {
					return;
				}
				applyAction( action, state );
				renderBuilder( container, textarea, state );
			} );

			container.addEventListener( 'change', function ( event ) {
				var target = event.target;
				if ( target && target.getAttribute && target.getAttribute( 'data-pressqueue-condition-action' ) === 'set-mode' ) {
					var selected = getNode( state.tree, state.selectedPath );
					if ( selected && selected.type === 'group' ) {
						selected.mode = target.value;
						delete selected.operator;
						markTreeChanged( state );
						renderBuilder( container, textarea, state );
					}
				}
			} );

			textarea.addEventListener( 'change', function () {
				var reparsed = parseTextarea( textarea, state.tree );
				if ( reparsed.error ) {
					state.error = reparsed.error;
					state.rawInvalid = true;
					renderError( container, state.error );
					return;
				}
				state.tree = reparsed.tree;
				state.error = null;
				state.rawInvalid = false;
				state.selectedPath = [];
				renderBuilder( container, textarea, state );
			} );

			renderBuilder( container, textarea, state );
		} );
	} );
}( window.wp || {} ) );
