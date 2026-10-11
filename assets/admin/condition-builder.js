/**
 * PressQueue "Which posts" condition builder.
 *
 * The canonical condition JSON textarea stays the submitted source of truth.
 * This builder shows a flat group of conditions as rows (field, comparison,
 * value) and writes the JSON back only after a person changes something.
 * Trees it cannot show, such as nested groups, stay in the JSON editor.
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

	var SIMPLE_CONDITIONS = [ 'post_type', 'author', 'taxonomy', 'metadata' ];

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

	function isId( value ) {
		return ( typeof value === 'number' && value > 0 && Math.floor( value ) === value ) || ( typeof value === 'string' && /^[0-9]+$/.test( value ) && parseInt( value, 10 ) > 0 );
	}

	function rootDocument( row ) {
		return {
			children: [ row ],
			mode: 'ALL',
			schema_version: 1,
			type: 'group'
		};
	}

	function parseTextarea( textarea ) {
		var raw = textarea.value || '';
		if ( ! raw.trim() ) {
			return { error: __( 'Condition JSON is invalid: it is empty.', 'pressqueue' ), rawInvalid: true, tree: null };
		}

		try {
			var parsed = JSON.parse( raw );
			if ( ! parsed || typeof parsed !== 'object' || Array.isArray( parsed ) || parsed.type !== 'group' ) {
				return { error: __( 'Condition JSON must be an object with a group root.', 'pressqueue' ), rawInvalid: true, tree: null };
			}
			return { error: null, rawInvalid: false, tree: parsed };
		} catch ( error ) {
			return { error: __( 'Condition JSON is invalid:', 'pressqueue' ) + ' ' + error.message, rawInvalid: true, tree: null };
		}
	}

	function writeTextarea( textarea, tree ) {
		textarea.value = JSON.stringify( tree, null, 2 );
	}

	/* ---------- Tree <-> rows ---------- */

	function rowFromNode( node ) {
		if ( ! node || node.type !== 'condition' || SIMPLE_CONDITIONS.indexOf( node.condition ) === -1 || typeof node.operator !== 'string' ) {
			return null;
		}

		var value = node.value;

		if ( node.condition === 'post_type' ) {
			if ( node.operator === 'equals' && typeof value === 'string' && value ) {
				return { field: 'post_type', values: [ value ], originalOperator: 'equals' };
			}
			if ( node.operator === 'in' && Array.isArray( value ) && value.every( function ( item ) {
				return typeof item === 'string' && item;
			} ) ) {
				return { field: 'post_type', values: value.slice(), originalOperator: 'in' };
			}
			return null;
		}

		if ( node.condition === 'author' ) {
			if ( node.operator === 'equals' && isId( value ) ) {
				return { field: 'author', values: [ parseInt( value, 10 ) ], originalOperator: 'equals' };
			}
			if ( node.operator === 'in' && Array.isArray( value ) && value.every( isId ) ) {
				return { field: 'author', values: value.map( function ( item ) {
					return parseInt( item, 10 );
				} ), originalOperator: 'in' };
			}
			return null;
		}

		if ( node.condition === 'taxonomy' ) {
			if ( [ 'has_any', 'has_all', 'has_none' ].indexOf( node.operator ) === -1 || ! value || typeof value !== 'object' ) {
				return null;
			}
			if ( typeof value.taxonomy !== 'string' || typeof value.include_ancestors !== 'boolean' || ! Array.isArray( value.terms ) || ! value.terms.every( isId ) ) {
				return null;
			}
			return {
				field: 'tax:' + value.taxonomy.toLowerCase(),
				operator: node.operator,
				values: value.terms.map( function ( item ) {
					return parseInt( item, 10 );
				} ),
				includeAncestors: value.include_ancestors
			};
		}

		if ( ! value || typeof value !== 'object' || typeof value.key !== 'string' ) {
			return null;
		}
		if ( node.operator === 'exists' || node.operator === 'not_exists' ) {
			return { field: 'meta', operator: node.operator, key: value.key, value: '', valueType: 'string' };
		}
		if ( ( node.operator === 'equals' || node.operator === 'not_equals' ) && Object.prototype.hasOwnProperty.call( value, 'value' ) ) {
			var typed = value.value;
			var type = 'string';
			var raw = typed;
			if ( typed && typeof typed === 'object' && ! Array.isArray( typed ) && typeof typed.type === 'string' ) {
				type = typed.type;
				raw = typed.value;
			} else if ( typeof typed === 'number' ) {
				type = 'number';
			} else if ( typeof typed === 'boolean' ) {
				type = 'boolean';
			} else if ( typed === null ) {
				type = 'null';
			} else if ( typeof typed !== 'string' ) {
				return null;
			}
			return { field: 'meta', operator: node.operator, key: value.key, value: raw === null ? '' : String( raw ), valueType: type };
		}
		return null;
	}

	function rowsFromTree( tree ) {
		if ( ! tree || tree.type !== 'group' ) {
			return null;
		}
		var mode = tree.mode || tree.operator;
		if ( ( mode !== 'ALL' && mode !== 'ANY' ) || ! Array.isArray( tree.children ) || ! tree.children.length ) {
			return null;
		}
		var rows = [];
		for ( var i = 0; i < tree.children.length; i++ ) {
			var row = rowFromNode( tree.children[ i ] );
			if ( ! row ) {
				return null;
			}
			rows.push( row );
		}
		return { mode: mode, rows: rows };
	}

	function nodeFromRow( row ) {
		if ( row.field === 'post_type' || row.field === 'author' ) {
			var useList = row.values.length !== 1 || row.originalOperator === 'in';
			return {
				condition: row.field,
				operator: useList ? 'in' : 'equals',
				type: 'condition',
				value: useList ? row.values.slice() : row.values[ 0 ]
			};
		}

		if ( row.field.indexOf( 'tax:' ) === 0 ) {
			return {
				condition: 'taxonomy',
				operator: row.operator,
				type: 'condition',
				value: {
					include_ancestors: !! row.includeAncestors,
					taxonomy: row.field.slice( 4 ),
					terms: row.values.slice()
				}
			};
		}

		if ( row.operator === 'exists' || row.operator === 'not_exists' ) {
			return { condition: 'metadata', operator: row.operator, type: 'condition', value: { key: row.key } };
		}

		return {
			condition: 'metadata',
			operator: row.operator,
			type: 'condition',
			value: { key: row.key, value: typedValue( row ) }
		};
	}

	function typedValue( row ) {
		var raw = row.value === undefined ? '' : String( row.value );
		if ( row.valueType === 'number' && raw.trim() !== '' && ! isNaN( Number( raw ) ) ) {
			return { type: 'number', value: Number( raw ) };
		}
		if ( row.valueType === 'boolean' && ( raw === 'true' || raw === 'false' ) ) {
			return { type: 'boolean', value: raw === 'true' };
		}
		if ( row.valueType === 'null' && raw === '' ) {
			return { type: 'null', value: null };
		}
		return { type: 'string', value: raw };
	}

	function treeFromState( state ) {
		var tree = {
			children: state.rows.map( nodeFromRow ),
			mode: state.mode,
			schema_version: 1,
			type: 'group'
		};
		return tree;
	}

	/* ---------- Data helpers ---------- */

	function taxonomyFor( state, field ) {
		var slug = field.slice( 4 );
		for ( var i = 0; i < state.data.taxonomies.length; i++ ) {
			if ( state.data.taxonomies[ i ].slug === slug ) {
				return state.data.taxonomies[ i ];
			}
		}
		return { slug: slug, label: slug, plural: slug, hierarchical: false, terms: [], more: false, rest: '' };
	}

	function customFields( state ) {
		var input = state.form ? state.form.querySelector( '[data-pq-custom-fields]' ) : document.querySelector( '[data-pq-custom-fields]' );
		if ( ! input ) {
			return [];
		}
		return input.value.split( ',' ).map( function ( item ) {
			return item.trim();
		} ).filter( function ( item, index, list ) {
			return item && list.indexOf( item ) === index;
		} );
	}

	function fieldChoices( state, row ) {
		var choices = [ { value: 'post_type', label: __( 'Post type', 'pressqueue' ) } ];
		state.data.taxonomies.forEach( function ( taxonomy ) {
			choices.push( { value: 'tax:' + taxonomy.slug, label: taxonomy.label } );
		} );
		if ( row && row.field.indexOf( 'tax:' ) === 0 && ! choices.some( function ( choice ) {
			return choice.value === row.field;
		} ) ) {
			choices.push( { value: row.field, label: row.field.slice( 4 ) } );
		}
		choices.push( { value: 'author', label: __( 'Author', 'pressqueue' ) } );
		if ( customFields( state ).length || ( row && row.field === 'meta' ) ) {
			choices.push( { value: 'meta', label: __( 'Custom field', 'pressqueue' ) } );
		}
		return choices;
	}

	function operatorChoices( row ) {
		if ( row.field === 'post_type' || row.field === 'author' ) {
			return [ { value: 'is', label: __( 'is', 'pressqueue' ) } ];
		}
		if ( row.field === 'meta' ) {
			return [
				{ value: 'equals', label: __( 'equals', 'pressqueue' ) },
				{ value: 'not_equals', label: __( 'does not equal', 'pressqueue' ) },
				{ value: 'exists', label: __( 'exists', 'pressqueue' ) },
				{ value: 'not_exists', label: __( 'does not exist', 'pressqueue' ) }
			];
		}
		return [
			{ value: 'has_any', label: __( 'is any of', 'pressqueue' ) },
			{ value: 'has_all', label: __( 'is all of', 'pressqueue' ) },
			{ value: 'has_none', label: __( 'is none of', 'pressqueue' ) }
		];
	}

	function defaultRow( state, field ) {
		if ( field === 'post_type' ) {
			var hasPost = state.data.postTypes.some( function ( type ) {
				return type.value === 'post';
			} );
			return { field: 'post_type', values: hasPost ? [ 'post' ] : [], originalOperator: 'equals' };
		}
		if ( field === 'author' ) {
			return { field: 'author', values: [], originalOperator: 'equals' };
		}
		if ( field === 'meta' ) {
			var fields = customFields( state );
			return { field: 'meta', operator: 'equals', key: fields.length ? fields[ 0 ] : '', value: '', valueType: 'string' };
		}
		var taxonomy = taxonomyFor( state, field );
		return { field: field, operator: 'has_any', values: [], includeAncestors: !! taxonomy.hierarchical };
	}

	function itemsFor( state, row ) {
		if ( row.field === 'post_type' ) {
			return state.data.postTypes.map( function ( type ) {
				return { value: type.value, label: type.label, path: '' };
			} );
		}
		if ( row.field === 'author' ) {
			return state.data.authors.map( function ( author ) {
				return { value: author.id, label: author.name, path: '' };
			} );
		}
		return taxonomyFor( state, row.field ).terms.map( function ( term ) {
			return { value: term.id, label: term.name, path: term.path || '' };
		} );
	}

	/* ---------- Validation ---------- */

	function rowProblem( state, row ) {
		if ( row.field === 'post_type' && ! row.values.length ) {
			return __( 'Pick at least one post type.', 'pressqueue' );
		}
		if ( row.field === 'author' && ! row.values.length ) {
			return __( 'Pick at least one author.', 'pressqueue' );
		}
		if ( row.field.indexOf( 'tax:' ) === 0 && ! row.values.length ) {
			return sprintf( /* translators: %s: taxonomy name, for example category. */ __( 'Pick at least one %s.', 'pressqueue' ), taxonomyFor( state, row.field ).label.toLowerCase() );
		}
		if ( row.field === 'meta' ) {
			if ( ! row.key ) {
				return __( 'Choose a custom field. Add its name under Advanced first.', 'pressqueue' );
			}
			if ( customFields( state ).indexOf( row.key ) === -1 ) {
				return sprintf( /* translators: %s: custom field name. */ __( 'Add "%s" to the custom fields under Advanced, or choose another field.', 'pressqueue' ), row.key );
			}
		}
		return '';
	}

	/* ---------- Rendering ---------- */

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

	function select( id, choices, current ) {
		var node = document.createElement( 'select' );
		node.id = id;
		choices.forEach( function ( choice ) {
			var option = document.createElement( 'option' );
			option.value = choice.value;
			option.textContent = choice.label;
			option.selected = choice.value === current;
			node.appendChild( option );
		} );
		return node;
	}

	function renderError( container, message ) {
		var host = container.querySelector( '.pq-builder-ui' );
		var error = host.querySelector( '.pressqueue-condition-builder__error' );
		if ( ! error ) {
			error = el( 'p', 'pressqueue-condition-builder__error pq-field-error' );
			error.setAttribute( 'role', 'alert' );
			host.insertBefore( error, host.firstChild );
		}
		error.textContent = message || '';
		error.hidden = ! message;
	}

	function render( state ) {
		var host = state.container.querySelector( '.pq-builder-ui' );
		host.innerHTML = '';
		state.controlIndex = 0;

		var error = el( 'p', 'pressqueue-condition-builder__error pq-field-error' );
		error.setAttribute( 'role', 'alert' );
		host.appendChild( error );
		renderError( state.container, state.error );

		if ( ! state.simple ) {
			renderAdvancedNote( state, host );
			return;
		}

		var list = el( 'ul', 'pq-builder-rows pressqueue-condition-builder__children' );
		state.rows.forEach( function ( row, index ) {
			list.appendChild( renderRow( state, row, index ) );
		} );
		host.appendChild( list );

		var foot = el( 'div', 'pq-builder-foot' );
		var add = el( 'button', 'button button-small', __( '+ Add condition', 'pressqueue' ) );
		add.type = 'button';
		add.setAttribute( 'data-pq-focus', 'add' );
		add.addEventListener( 'click', function () {
			var field = state.effect === 'exclude' ? 'tax:category' : ( state.rows.some( function ( row ) {
				return row.field === 'tax:category';
			} ) ? 'tax:post_tag' : 'tax:category' );
			if ( ! state.data.taxonomies.some( function ( taxonomy ) {
				return 'tax:' + taxonomy.slug === field;
			} ) ) {
				field = 'author';
			}
			state.rows.push( defaultRow( state, field ) );
			changed( state, 'value-' + ( state.rows.length - 1 ) );
		} );
		foot.appendChild( add );

		if ( state.rows.length > 1 ) {
			var modeId = nextControlId( state, [], 'mode' );
			var wrap = el( 'span', 'pq-muted pq-small' );
			var before = el( 'label', '', __( 'Posts must match', 'pressqueue' ) + ' ' );
			before.setAttribute( 'for', modeId );
			var mode = select( modeId, [
				{ value: 'ALL', label: __( 'all', 'pressqueue' ) },
				{ value: 'ANY', label: __( 'any', 'pressqueue' ) }
			], state.mode );
			mode.setAttribute( 'data-pq-focus', 'mode' );
			mode.addEventListener( 'change', function () {
				state.mode = mode.value;
				changed( state, 'mode' );
			} );
			wrap.appendChild( before );
			wrap.appendChild( mode );
			wrap.appendChild( document.createTextNode( ' ' + __( 'of these conditions.', 'pressqueue' ) ) );
			foot.appendChild( wrap );
		} else {
			foot.appendChild( el( 'span', 'pq-muted pq-small', state.effect === 'exclude' ? __( 'Add more conditions to narrow down which posts are kept private.', 'pressqueue' ) : __( 'Add more conditions to share fewer posts.', 'pressqueue' ) ) );
		}
		host.appendChild( foot );
	}

	function renderAdvancedNote( state, host ) {
		var note = el( 'div', 'pq-callout pq-callout--warn pq-builder-note' );
		note.appendChild( el( 'p', '', __( 'These conditions use nested groups or other options this editor cannot show. They still work. Edit them as JSON under Advanced.', 'pressqueue' ) ) );
		var actions = el( 'p', 'pq-row' );
		var show = el( 'button', 'button button-small', __( 'Show the JSON', 'pressqueue' ) );
		show.type = 'button';
		show.addEventListener( 'click', function () {
			var details = state.textarea.closest( 'details' );
			if ( details ) {
				details.open = true;
			}
			state.textarea.focus();
		} );
		var reset = el( 'button', 'button-link', __( 'Replace them with simple conditions', 'pressqueue' ) );
		reset.type = 'button';
		reset.addEventListener( 'click', function () {
			if ( ! window.confirm( __( 'Replace the current conditions with one simple condition? You can then add more.', 'pressqueue' ) ) ) {
				return;
			}
			state.mode = 'ALL';
			state.rows = [ defaultRow( state, state.effect === 'exclude' ? 'tax:category' : 'post_type' ) ];
			state.simple = true;
			state.error = null;
			state.rawInvalid = false;
			changed( state, 'value-0' );
		} );
		actions.appendChild( show );
		actions.appendChild( reset );
		note.appendChild( actions );
		host.appendChild( note );
	}

	function renderRow( state, row, index ) {
		var item = el( 'li', 'pq-cond' );
		var path = [ index ];
		var fieldId = nextControlId( state, path, 'condition' );
		var operatorId = nextControlId( state, path, 'operator' );

		item.appendChild( controlLabel( fieldId, sprintf( /* translators: %d: condition number. */ __( 'Condition %d: field', 'pressqueue' ), index + 1 ) ) );
		var field = select( fieldId, fieldChoices( state, row ), row.field );
		field.setAttribute( 'data-pq-focus', 'field-' + index );
		field.addEventListener( 'change', function () {
			state.rows[ index ] = defaultRow( state, field.value );
			changed( state, 'value-' + index );
		} );
		item.appendChild( field );

		var operators = operatorChoices( row );
		if ( operators.length === 1 ) {
			item.appendChild( el( 'span', 'pq-cond-op', operators[ 0 ].label ) );
		} else {
			item.appendChild( controlLabel( operatorId, sprintf( /* translators: %d: condition number. */ __( 'Condition %d: comparison', 'pressqueue' ), index + 1 ) ) );
			var operator = select( operatorId, operators, row.operator );
			operator.setAttribute( 'data-pq-focus', 'operator-' + index );
			operator.addEventListener( 'change', function () {
				row.operator = operator.value;
				changed( state, 'operator-' + index );
			} );
			item.appendChild( operator );
		}

		if ( row.field === 'meta' ) {
			item.appendChild( renderMetaValue( state, row, index ) );
		} else {
			item.appendChild( renderChipValue( state, row, index ) );
		}

		if ( row.field.indexOf( 'tax:' ) === 0 && taxonomyFor( state, row.field ).hierarchical ) {
			var subId = nextControlId( state, path, 'children' );
			var sub = el( 'label', 'pq-cond-sub' );
			sub.setAttribute( 'for', subId );
			var box = document.createElement( 'input' );
			box.type = 'checkbox';
			box.id = subId;
			box.checked = !! row.includeAncestors;
			box.setAttribute( 'data-pq-focus', 'children-' + index );
			box.addEventListener( 'change', function () {
				row.includeAncestors = box.checked;
				changed( state, 'children-' + index );
			} );
			sub.appendChild( box );
			sub.appendChild( document.createTextNode( row.field === 'tax:category' ? __( 'Include subcategories', 'pressqueue' ) : __( 'Include nested terms', 'pressqueue' ) ) );
			item.appendChild( sub );
		}

		var remove = el( 'button', 'button-link pq-cond-remove', __( 'Remove', 'pressqueue' ) );
		remove.type = 'button';
		remove.setAttribute( 'aria-label', sprintf( /* translators: %d: condition number. */ __( 'Remove condition %d', 'pressqueue' ), index + 1 ) );
		remove.setAttribute( 'data-pq-focus', 'remove-' + index );
		if ( state.rows.length === 1 ) {
			remove.disabled = true;
			remove.title = __( 'A rule needs at least one condition.', 'pressqueue' );
		}
		remove.addEventListener( 'click', function () {
			state.rows.splice( index, 1 );
			changed( state, state.rows.length ? 'remove-' + Math.max( 0, index - 1 ) : 'add' );
		} );
		item.appendChild( remove );

		var problem = state.showProblems ? rowProblem( state, row ) : '';
		if ( problem ) {
			item.classList.add( 'is-invalid' );
			item.appendChild( el( 'p', 'pq-cond-error', problem ) );
		}

		return item;
	}

	function renderMetaValue( state, row, index ) {
		var wrap = el( 'div', 'pq-cond-value' );
		var keyId = nextControlId( state, [ index ], 'key' );
		var fields = customFields( state );
		var choices = fields.map( function ( key ) {
			return { value: key, label: key };
		} );
		if ( row.key && fields.indexOf( row.key ) === -1 ) {
			choices.unshift( { value: row.key, label: sprintf( /* translators: %s: custom field name. */ __( '%s (not listed under Advanced)', 'pressqueue' ), row.key ) } );
		}
		if ( ! choices.length ) {
			choices.push( { value: '', label: __( 'Add a custom field under Advanced', 'pressqueue' ) } );
		}
		wrap.appendChild( controlLabel( keyId, sprintf( /* translators: %d: condition number. */ __( 'Condition %d: custom field name', 'pressqueue' ), index + 1 ) ) );
		var key = select( keyId, choices, row.key );
		key.setAttribute( 'data-pq-focus', 'key-' + index );
		key.addEventListener( 'change', function () {
			row.key = key.value;
			changed( state, 'key-' + index );
		} );
		wrap.appendChild( key );

		if ( row.operator === 'equals' || row.operator === 'not_equals' ) {
			var valueId = nextControlId( state, [ index ], 'value' );
			wrap.appendChild( controlLabel( valueId, sprintf( /* translators: %d: condition number. */ __( 'Condition %d: value', 'pressqueue' ), index + 1 ) ) );
			var input = document.createElement( 'input' );
			input.type = 'text';
			input.id = valueId;
			input.value = row.value;
			input.placeholder = __( 'Value', 'pressqueue' );
			input.setAttribute( 'data-pq-focus', 'value-' + index );
			input.addEventListener( 'input', function () {
				row.value = input.value;
				sync( state );
			} );
			wrap.appendChild( input );
		}
		return wrap;
	}

	function renderChipValue( state, row, index ) {
		var wrap = el( 'div', 'pq-cond-value' );
		var inputId = nextControlId( state, [ index ], 'value' );
		var listId = inputId + '-list';
		var box = el( 'div', 'pq-chipbox' );
		var input = document.createElement( 'input' );
		var list = el( 'ul', 'pq-suggest' );
		var items = itemsFor( state, row );
		var taxonomy = row.field.indexOf( 'tax:' ) === 0 ? taxonomyFor( state, row.field ) : null;
		var active = -1;
		var current = [];
		var timer = null;

		function labelFor( value ) {
			for ( var i = 0; i < items.length; i++ ) {
				if ( String( items[ i ].value ) === String( value ) ) {
					return items[ i ];
				}
			}
			return { value: value, label: row.field === 'author' ? sprintf( /* translators: %s: user ID. */ __( 'User #%s', 'pressqueue' ), value ) : String( value ), path: '' };
		}

		row.values.forEach( function ( value ) {
			var item = labelFor( value );
			var chip = el( 'span', 'pq-chip' );
			var text = el( 'span', 'pq-chip-text' );
			if ( item.path ) {
				text.appendChild( el( 'span', 'pq-chip-path', item.path + ' › ' ) );
			}
			text.appendChild( document.createTextNode( item.label ) );
			chip.appendChild( text );
			var remove = el( 'button', '', '×' );
			remove.type = 'button';
			remove.setAttribute( 'aria-label', sprintf( /* translators: %s: name of the item to remove. */ __( 'Remove %s', 'pressqueue' ), item.label ) );
			remove.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
				row.values = row.values.filter( function ( other ) {
					return String( other ) !== String( value );
				} );
				changed( state, 'value-' + index );
			} );
			chip.appendChild( remove );
			box.appendChild( chip );
		} );

		input.type = 'text';
		input.id = inputId;
		input.autocomplete = 'off';
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', listId );
		input.setAttribute( 'data-pq-focus', 'value-' + index );
		input.placeholder = placeholderFor( state, row );
		box.appendChild( input );

		list.id = listId;
		list.setAttribute( 'role', 'listbox' );
		list.hidden = true;

		function open() {
			var query = input.value.trim().toLowerCase();
			current = items.filter( function ( item ) {
				var selected = row.values.some( function ( value ) {
					return String( value ) === String( item.value );
				} );
				return ! selected && ( ! query || item.label.toLowerCase().indexOf( query ) !== -1 || ( item.path && item.path.toLowerCase().indexOf( query ) !== -1 ) );
			} ).slice( 0, 50 );
			list.innerHTML = '';
			active = current.length ? 0 : -1;
			current.forEach( function ( item, position ) {
				var option = el( 'li' );
				option.id = listId + '-' + position;
				option.setAttribute( 'role', 'option' );
				option.setAttribute( 'aria-selected', position === active ? 'true' : 'false' );
				option.appendChild( document.createTextNode( item.label ) );
				if ( item.path ) {
					option.appendChild( el( 'span', 'pq-chip-path', item.path ) );
				}
				option.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
					choose( item );
				} );
				list.appendChild( option );
			} );
			if ( ! current.length ) {
				var empty = el( 'li', 'pq-suggest-empty', query ? __( 'No matches.', 'pressqueue' ) : __( 'Everything is already picked.', 'pressqueue' ) );
				empty.setAttribute( 'role', 'option' );
				empty.setAttribute( 'aria-disabled', 'true' );
				list.appendChild( empty );
			}
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			highlight();
		}

		function close() {
			list.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
		}

		function highlight() {
			Array.prototype.forEach.call( list.children, function ( option, position ) {
				option.setAttribute( 'aria-selected', position === active ? 'true' : 'false' );
			} );
			if ( active >= 0 && list.children[ active ] ) {
				input.setAttribute( 'aria-activedescendant', list.children[ active ].id );
				list.children[ active ].scrollIntoView( { block: 'nearest' } );
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}

		function choose( item ) {
			row.values.push( item.value );
			changed( state, 'value-' + index );
		}

		function remoteSearch() {
			if ( ! taxonomy || ! taxonomy.rest || input.value.trim().length < 2 || ! window.fetch ) {
				return;
			}
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				var url = taxonomy.rest + ( taxonomy.rest.indexOf( '?' ) === -1 ? '?' : '&' ) + 'per_page=20&_fields=id,name&search=' + encodeURIComponent( input.value.trim() );
				window.fetch( url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': state.data.restNonce } } ).then( function ( response ) {
					return response.ok ? response.json() : [];
				} ).then( function ( terms ) {
					if ( ! Array.isArray( terms ) ) {
						return;
					}
					terms.forEach( function ( term ) {
						if ( ! taxonomy.terms.some( function ( known ) {
							return known.id === term.id;
						} ) ) {
							taxonomy.terms.push( { id: term.id, name: String( term.name ), path: '' } );
							items.push( { value: term.id, label: String( term.name ), path: '' } );
						}
					} );
					if ( document.activeElement === input ) {
						open();
					}
				} ).catch( function () {} );
			}, 250 );
		}

		input.addEventListener( 'focus', open );
		input.addEventListener( 'input', function () {
			open();
			remoteSearch();
		} );
		input.addEventListener( 'blur', function () {
			window.setTimeout( close, 120 );
		} );
		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'ArrowDown' ) {
				event.preventDefault();
				if ( list.hidden ) {
					open();
				} else if ( current.length ) {
					active = ( active + 1 ) % current.length;
					highlight();
				}
			} else if ( event.key === 'ArrowUp' ) {
				event.preventDefault();
				if ( current.length ) {
					active = active <= 0 ? current.length - 1 : active - 1;
					highlight();
				}
			} else if ( event.key === 'Enter' ) {
				event.preventDefault();
				if ( ! list.hidden && active >= 0 && current[ active ] ) {
					choose( current[ active ] );
				}
			} else if ( event.key === 'Escape' ) {
				if ( ! list.hidden ) {
					event.preventDefault();
					close();
				}
			} else if ( event.key === 'Backspace' && ! input.value && row.values.length ) {
				row.values.pop();
				changed( state, 'value-' + index );
			}
		} );
		box.addEventListener( 'click', function () {
			input.focus();
		} );

		wrap.appendChild( controlLabel( inputId, sprintf( /* translators: %d: condition number. */ __( 'Condition %d: values', 'pressqueue' ), index + 1 ) ) );
		wrap.appendChild( box );
		wrap.appendChild( list );
		return wrap;
	}

	function placeholderFor( state, row ) {
		if ( row.field === 'post_type' ) {
			return row.values.length ? __( 'Add a post type…', 'pressqueue' ) : __( 'Pick a post type…', 'pressqueue' );
		}
		if ( row.field === 'author' ) {
			return row.values.length ? __( 'Add an author…', 'pressqueue' ) : __( 'Type an author name…', 'pressqueue' );
		}
		var taxonomy = taxonomyFor( state, row.field );
		return sprintf( row.values.length ? /* translators: %s: taxonomy or field name. */ __( 'Add a %s…', 'pressqueue' ) : /* translators: %s: taxonomy or field name. */ __( 'Type a %s name…', 'pressqueue' ), taxonomy.label.toLowerCase() );
	}

	/* ---------- State changes ---------- */

	function sync( state ) {
		if ( ! state.simple ) {
			return;
		}
		writeTextarea( state.textarea, treeFromState( state ) );
		state.rawInvalid = false;
		state.error = null;
	}

	function changed( state, focusKey ) {
		sync( state );
		render( state );
		if ( focusKey ) {
			var target = state.container.querySelector( '[data-pq-focus="' + focusKey + '"]' );
			if ( target && ! target.disabled ) {
				target.focus();
			}
		}
	}

	function loadFromTextarea( state ) {
		var parsed = parseTextarea( state.textarea );
		if ( parsed.rawInvalid ) {
			state.error = parsed.error;
			state.rawInvalid = true;
			return;
		}
		var simple = rowsFromTree( parsed.tree );
		state.error = null;
		state.rawInvalid = false;
		state.simple = !! simple;
		if ( simple ) {
			state.mode = simple.mode;
			state.rows = simple.rows;
		}
	}

	function validate( state ) {
		if ( state.rawInvalid ) {
			var details = state.textarea.closest( 'details' );
			if ( details ) {
				details.open = true;
			}
			return { element: state.textarea, message: __( 'The conditions JSON under Advanced is not valid. Fix it before saving.', 'pressqueue' ) };
		}
		if ( ! state.simple ) {
			return null;
		}
		state.showProblems = true;
		var problem = null;
		state.rows.forEach( function ( row, index ) {
			if ( ! problem && rowProblem( state, row ) ) {
				problem = { index: index, message: rowProblem( state, row ) };
			}
		} );
		render( state );
		if ( ! problem ) {
			return null;
		}
		return {
			element: state.container.querySelector( '[data-pq-focus="value-' + problem.index + '"]' ) || state.container.querySelector( '[data-pq-focus="key-' + problem.index + '"]' ),
			message: problem.message
		};
	}

	function init( container, index ) {
		var textarea = document.getElementById( container.getAttribute( 'data-target' ) );
		var data;
		if ( ! textarea ) {
			return;
		}
		try {
			data = JSON.parse( container.getAttribute( 'data-pq-builder' ) || '{}' );
		} catch ( error ) {
			data = {};
		}
		data.postTypes = data.postTypes || [];
		data.taxonomies = data.taxonomies || [];
		data.authors = data.authors || [];

		var state = {
			container: container,
			controlIndex: 0,
			data: data,
			effect: container.getAttribute( 'data-pq-effect' ) || 'include',
			error: null,
			form: textarea.form,
			instanceId: index + 1,
			mode: 'ALL',
			rawInvalid: false,
			rows: [],
			showProblems: false,
			simple: true,
			textarea: textarea
		};

		loadFromTextarea( state );
		if ( state.rawInvalid ) {
			state.simple = false;
		}

		textarea.addEventListener( 'change', function () {
			loadFromTextarea( state );
			if ( state.rawInvalid ) {
				renderError( container, state.error );
				return;
			}
			render( state );
		} );

		var fieldsInput = state.form ? state.form.querySelector( '[data-pq-custom-fields]' ) : null;
		if ( fieldsInput ) {
			fieldsInput.addEventListener( 'change', function () {
				render( state );
			} );
		}

		container.pqBuilder = {
			validate: function () {
				return validate( state );
			}
		};

		render( state );
	}

	function ready( callback ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', callback );
		} else {
			callback();
		}
	}

	ready( function () {
		document.querySelectorAll( '.pressqueue-condition-builder' ).forEach( init );
	} );

	window.pressqueueConditionBuilder = {
		rootDocument: rootDocument
	};
}( window.wp || {} ) );
