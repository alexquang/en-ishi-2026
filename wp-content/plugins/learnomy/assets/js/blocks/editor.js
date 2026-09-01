/**
 * Learnomy blocks — shared editor registration.
 *
 * The 12 Learnomy blocks are server-rendered (PHP render.php, no per-block JS).
 * Without a client-side registration the editor cannot render them and shows
 * "your site doesn't include support for this block". This one script registers
 * every block from the metadata PHP hands over in `window.learnomyBlockDefs`
 * (name / title / category / icon / attributes), giving each:
 *
 *   - a live ServerSideRender preview (same markup as the front end), and
 *   - InspectorControls a site owner can actually use.
 *
 * Content blocks pick their subject BY NAME, never by raw database ID. A site
 * owner has no way to know that "React Fundamentals" is course #153, so the
 * earlier auto-generated number/slider control made these blocks unusable. Each
 * content block now loads its list from REST and offers a searchable picker
 * (ComboboxControl), exactly like the Course Grid block already did. Until a
 * subject is chosen the block shows a friendly Placeholder instead of an empty
 * preview.
 *
 * No build step: uses the wp.* globals via wp.element.createElement.
 *
 * @package Learnomy
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor || ! wp.components ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var RangeControl = wp.components.RangeControl;
	var SelectControl = wp.components.SelectControl;
	var ComboboxControl = wp.components.ComboboxControl;
	var FormTokenField = wp.components.FormTokenField;
	var Placeholder = wp.components.Placeholder;
	var Spinner = wp.components.Spinner;
	var ServerSideRender = wp.serverSideRender;
	var apiFetch = wp.apiFetch;
	var __ = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };

	var defs = window.learnomyBlockDefs || [];
	if ( ! Array.isArray( defs ) || ! defs.length ) {
		return;
	}

	/** Normalise a REST list response that may be {data:[…]} or a bare array. */
	function listOf( res ) {
		if ( Array.isArray( res ) ) { return res; }
		if ( res && Array.isArray( res.data ) ) { return res.data; }
		return [];
	}

	/** A list item's display name across our envelopes + core /wp/v2/users. */
	function nameOf( item, id ) {
		return item.title || item.name || ( '#' + id );
	}

	function ssrPreview( name, attrs ) {
		return ServerSideRender
			? el( ServerSideRender, { key: 'ssr', block: name, attributes: attrs } )
			: el( 'p', { key: 'ssr' }, name );
	}

	/**
	 * Load a REST list once, mapping each row with mapper(row) -> option.
	 * Returns [ options, loading, failed ].
	 */
	function useList( path, mapper ) {
		var s = useState( { items: [], loading: !! path, failed: false } );
		var state = s[ 0 ], setState = s[ 1 ];
		useEffect( function () {
			if ( ! path || ! apiFetch ) { setState( { items: [], loading: false, failed: ! apiFetch } ); return; }
			var cancelled = false;
			apiFetch( { path: path } )
				.then( function ( r ) {
					if ( cancelled ) { return; }
					var opts = listOf( r ).map( mapper ).filter( function ( o ) { return o && ( o.value || o.value === 0 ) && o.value !== ''; } );
					setState( { items: opts, loading: false, failed: false } );
				} )
				.catch( function () { if ( ! cancelled ) { setState( { items: [], loading: false, failed: true } ); } } );
			return function () { cancelled = true; };
		}, [] );
		return [ state.items, state.loading, state.failed ];
	}

	/** id+name list mapper for course/lesson/quiz/instructor pickers. */
	function idNameMapper( c ) {
		var id = Number( c.id );
		return { value: id, label: nameOf( c, id ) };
	}

	/**
	 * Edit component for a block that points at ONE subject chosen by name.
	 */
	function entityPickerEdit( name, icon, cfg ) {
		return function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var list = useList( cfg.path, idNameMapper );
			var options = list[ 0 ], loading = list[ 1 ], failed = list[ 2 ];
			var selected = a[ cfg.idAttr ] ? Number( a[ cfg.idAttr ] ) : 0;

			// Keep a saved-but-not-in-first-page id visible in the picker.
			var opts = options.slice();
			if ( selected && ! opts.some( function ( o ) { return o.value === selected; } ) ) {
				opts.unshift( { value: selected, label: '#' + selected } );
			}

			var controls = [];
			if ( loading ) {
				controls.push( el( 'p', { key: 'load' }, el( Spinner, {} ), ' ', __( 'Loading…', 'learnomy' ) ) );
			} else if ( opts.length ) {
				controls.push( el( ComboboxControl, {
					key: 'pick',
					label: cfg.pickerLabel,
					value: selected || null,
					options: opts,
					help: cfg.help,
					onChange: function ( v ) { var o = {}; o[ cfg.idAttr ] = v ? Number( v ) : undefined; set( o ); },
				} ) );
			} else {
				// Nothing to pick (none created yet, or the list endpoint denied
				// the request) — never a dead end: let the owner type the id.
				controls.push( el( TextControl, {
					key: 'fallback',
					type: 'number',
					label: cfg.pickerLabel,
					value: selected || '',
					help: cfg.emptyHelp || __( 'Enter the numeric ID.', 'learnomy' ),
					onChange: function ( v ) { var o = {}; o[ cfg.idAttr ] = v ? Number( v ) : undefined; set( o ); },
				} ) );
			}
			if ( cfg.extra ) {
				cfg.extra( props ).forEach( function ( c ) { controls.push( c ); } );
			}

			var body = selected
				? ssrPreview( name, a )
				: el( Placeholder, {
					key: 'ph',
					icon: icon,
					label: cfg.placeholderLabel,
					instructions: cfg.placeholderInstructions || __( 'Choose what to show in the block settings on the right.', 'learnomy' ),
				}, loading ? el( Spinner, {} ) : null );

			return el( Fragment, {},
				el( InspectorControls, { key: 'i' }, el( PanelBody, { title: cfg.pickerLabel, initialOpen: true }, controls ) ),
				el( 'div', useBlockProps ? useBlockProps() : {}, body )
			);
		};
	}

	/* ── Custom edit: course-grid ─────────────────────────────────────────── */
	function CourseGridEdit( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var courses = useList( '/learnomy/v1/courses?per_page=100', idNameMapper );
		var cats = useList( '/learnomy/v1/categories?per_page=100', idNameMapper );
		var courseOpts = courses[ 0 ];
		var catOpts = cats[ 0 ];

		var source = a.source || 'latest';
		var controls = [];

		controls.push( el( SelectControl, {
			key: 'source',
			label: __( 'Show', 'learnomy' ),
			value: source,
			options: [
				{ label: __( 'Latest courses', 'learnomy' ), value: 'latest' },
				{ label: __( 'Courses in a category', 'learnomy' ), value: 'category' },
				{ label: __( 'Specific courses I pick', 'learnomy' ), value: 'ids' },
			],
			onChange: function ( v ) { set( { source: v } ); },
		} ) );

		if ( 'ids' === source ) {
			// Map the saved CSV ids <-> course titles for the token field.
			var idArr = String( a.ids || '' ).split( ',' ).map( function ( s ) { return Number( s.trim() ); } ).filter( Boolean );
			var titleById = {}, idByTitle = {};
			courseOpts.forEach( function ( c ) { titleById[ c.value ] = c.label; idByTitle[ c.label.toLowerCase() ] = c.value; } );
			var selectedTitles = idArr.map( function ( id ) { return titleById[ id ] || ( '#' + id ); } );
			controls.push( el( FormTokenField, {
				key: 'courses',
				label: __( 'Courses', 'learnomy' ),
				value: selectedTitles,
				suggestions: courseOpts.map( function ( c ) { return c.label; } ),
				onChange: function ( tokens ) {
					var ids = tokens.map( function ( t ) {
						if ( idByTitle[ String( t ).toLowerCase() ] ) { return idByTitle[ String( t ).toLowerCase() ]; }
						var m = String( t ).match( /#(\d+)/ ); return m ? Number( m[ 1 ] ) : 0;
					} ).filter( Boolean );
					set( { ids: ids.join( ',' ) } );
				},
				__experimentalExpandOnFocus: true,
				help: __( 'Start typing a course title to add it. Order is preserved.', 'learnomy' ),
			} ) );
		} else if ( 'category' === source ) {
			var catSelect = [ { label: __( 'Select a category', 'learnomy' ), value: 0 } ].concat(
				catOpts.map( function ( c ) { return { label: c.label, value: c.value }; } )
			);
			controls.push( el( SelectControl, {
				key: 'category',
				label: __( 'Category', 'learnomy' ),
				value: a.category || 0,
				options: catSelect,
				onChange: function ( v ) { set( { category: Number( v ) } ); },
			} ) );
			controls.push( el( RangeControl, {
				key: 'count',
				label: __( 'Number to show', 'learnomy' ),
				value: a.count || 6, min: 1, max: 24,
				onChange: function ( v ) { set( { count: Number( v ) || 6 } ); },
			} ) );
		} else {
			controls.push( el( RangeControl, {
				key: 'count',
				label: __( 'Number to show', 'learnomy' ),
				value: a.count || 6, min: 1, max: 24,
				onChange: function ( v ) { set( { count: Number( v ) || 6 } ); },
			} ) );
		}

		controls.push( el( RangeControl, {
			key: 'columns',
			label: __( 'Columns', 'learnomy' ),
			value: a.columns || 3, min: 1, max: 6,
			onChange: function ( v ) { set( { columns: Number( v ) || 3 } ); },
		} ) );

		return el( Fragment, {},
			el( InspectorControls, { key: 'i' }, el( PanelBody, { title: __( 'Course Grid', 'learnomy' ), initialOpen: true }, controls ) ),
			el( 'div', useBlockProps ? useBlockProps() : {}, ssrPreview( 'learnomy/course-grid', a ) )
		);
	}

	/* ── Custom edit: course-catalog (category by slug + ranges) ───────────── */
	function CourseCatalogEdit( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		// Render resolves the category by SLUG, so options carry the slug and we
		// keep only course-type categories.
		var cats = useList( '/learnomy/v1/categories?per_page=100', function ( c ) {
			if ( c.type && 'course' !== c.type ) { return null; }
			return { value: c.slug || '', label: nameOf( c, c.id ) };
		} );
		var catList = cats[ 0 ];

		var catOptions = [ { label: __( 'All categories', 'learnomy' ), value: '' } ].concat( catList );
		// Keep a saved slug visible even if the list hasn't loaded it.
		if ( a.category && ! catList.some( function ( o ) { return o.value === a.category; } ) ) {
			catOptions.push( { label: a.category, value: a.category } );
		}

		var controls = [
			el( SelectControl, {
				key: 'category',
				label: __( 'Category', 'learnomy' ),
				value: a.category || '',
				options: catOptions,
				help: __( 'Limit the catalog to one category, or show all.', 'learnomy' ),
				onChange: function ( v ) { set( { category: v } ); },
			} ),
			el( RangeControl, {
				key: 'limit',
				label: __( 'Maximum courses', 'learnomy' ),
				value: a.limit || 12, min: 1, max: 48,
				onChange: function ( v ) { set( { limit: Number( v ) || 12 } ); },
			} ),
			el( RangeControl, {
				key: 'columns',
				label: __( 'Columns', 'learnomy' ),
				value: a.columns || 3, min: 1, max: 6,
				onChange: function ( v ) { set( { columns: Number( v ) || 3 } ); },
			} ),
		];

		return el( Fragment, {},
			el( InspectorControls, { key: 'i' }, el( PanelBody, { title: __( 'Course Catalog', 'learnomy' ), initialOpen: true }, controls ) ),
			el( 'div', useBlockProps ? useBlockProps() : {}, ssrPreview( 'learnomy/course-catalog', a ) )
		);
	}

	/* ── Custom edit: certificate (verification ID — text by design) ───────── */
	function CertificateEdit( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var uuid = a.uuid || '';
		var control = el( TextControl, {
			key: 'uuid',
			label: __( 'Verification ID (UUID)', 'learnomy' ),
			value: uuid,
			help: __( 'Paste a certificate\'s verification ID (UUID) to display it. Leave blank to hide the block.', 'learnomy' ),
			onChange: function ( v ) { set( { uuid: v } ); },
		} );
		var body = uuid
			? ssrPreview( 'learnomy/certificate', a )
			: el( Placeholder, {
				icon: 'awards',
				label: __( 'Certificate Verification', 'learnomy' ),
				instructions: __( 'Enter a certificate ID in the block settings to show its verified details.', 'learnomy' ),
			} );
		return el( Fragment, {},
			el( InspectorControls, { key: 'i' }, el( PanelBody, { title: __( 'Certificate', 'learnomy' ), initialOpen: true }, control ) ),
			el( 'div', useBlockProps ? useBlockProps() : {}, body )
		);
	}

	/* ── Per-block edit selection ─────────────────────────────────────────── */
	var COURSES = '/learnomy/v1/courses?per_page=100';

	function editFor( def ) {
		var name = def.name;
		var icon = def.icon || 'screenoptions';

		switch ( name ) {
			case 'learnomy/course-grid':
				return CourseGridEdit;
			case 'learnomy/course-catalog':
				return CourseCatalogEdit;
			case 'learnomy/certificate':
				return CertificateEdit;
			case 'learnomy/course-card':
				return entityPickerEdit( name, icon, {
					idAttr: 'courseId', path: COURSES,
					pickerLabel: __( 'Course', 'learnomy' ),
					placeholderLabel: __( 'Course Card', 'learnomy' ),
					help: __( 'The course to feature in this card.', 'learnomy' ),
					emptyHelp: __( 'No courses found. Create a course first, or enter its ID.', 'learnomy' ),
				} );
			case 'learnomy/enrollment-cta':
				return entityPickerEdit( name, icon, {
					idAttr: 'courseId', path: COURSES,
					pickerLabel: __( 'Course', 'learnomy' ),
					placeholderLabel: __( 'Enroll Button', 'learnomy' ),
					help: __( 'The course this button enrolls the visitor into.', 'learnomy' ),
					emptyHelp: __( 'No courses found. Create a course first, or enter its ID.', 'learnomy' ),
					extra: function ( props ) {
						return [ el( TextControl, {
							key: 'buttonText',
							label: __( 'Button text', 'learnomy' ),
							value: props.attributes.buttonText || '',
							onChange: function ( v ) { props.setAttributes( { buttonText: v } ); },
						} ) ];
					},
				} );
			case 'learnomy/progress-bar':
				return entityPickerEdit( name, icon, {
					idAttr: 'courseId', path: COURSES,
					pickerLabel: __( 'Course', 'learnomy' ),
					placeholderLabel: __( 'Course Progress Bar', 'learnomy' ),
					help: __( 'Shows the logged-in visitor\'s progress in this course.', 'learnomy' ),
					emptyHelp: __( 'No courses found. Create a course first, or enter its ID.', 'learnomy' ),
					extra: function ( props ) {
						return [ el( TextControl, {
							key: 'userId',
							type: 'number',
							label: __( 'Specific user ID (optional)', 'learnomy' ),
							value: props.attributes.userId || '',
							help: __( 'Leave blank to show the current visitor\'s progress.', 'learnomy' ),
							onChange: function ( v ) { props.setAttributes( { userId: v ? Number( v ) : 0 } ); },
						} ) ];
					},
				} );
			case 'learnomy/quiz':
				return entityPickerEdit( name, icon, {
					idAttr: 'quizId', path: '/learnomy/v1/quizzes?per_page=100',
					pickerLabel: __( 'Quiz', 'learnomy' ),
					placeholderLabel: __( 'Quiz', 'learnomy' ),
					help: __( 'The quiz to embed.', 'learnomy' ),
					emptyHelp: __( 'No quizzes found. Create a quiz first, or enter its ID.', 'learnomy' ),
				} );
			case 'learnomy/lesson-content':
				return entityPickerEdit( name, icon, {
					idAttr: 'lessonId', path: '/learnomy/v1/lessons?parent_only=true&per_page=100',
					pickerLabel: __( 'Lesson', 'learnomy' ),
					placeholderLabel: __( 'Lesson Content', 'learnomy' ),
					help: __( 'The lesson whose content to display.', 'learnomy' ),
					emptyHelp: __( 'No lessons found. Create a lesson first, or enter its ID.', 'learnomy' ),
				} );
			case 'learnomy/instructor-card':
				return entityPickerEdit( name, icon, {
					idAttr: 'userId', path: '/wp/v2/users?roles=lrn_instructor&per_page=100&_fields=id,name',
					pickerLabel: __( 'Instructor', 'learnomy' ),
					placeholderLabel: __( 'Instructor Card', 'learnomy' ),
					help: __( 'The instructor to feature.', 'learnomy' ),
					emptyHelp: __( 'No instructors found. Enter the user ID instead.', 'learnomy' ),
				} );
			default:
				// Attribute-free blocks (membership-pricing, my-progress,
				// student-dashboard): a live preview, no controls needed.
				return function ( props ) {
					return el( 'div', useBlockProps ? useBlockProps() : {}, ssrPreview( name, props.attributes ) );
				};
		}
	}

	defs.forEach( function ( def ) {
		if ( ! def || ! def.name ) { return; }
		if ( wp.blocks.getBlockType && wp.blocks.getBlockType( def.name ) ) { return; }

		var attributes = ( def.attributes && typeof def.attributes === 'object' ) ? def.attributes : {};

		registerBlockType( def.name, {
			apiVersion: 2,
			title: def.title || def.name,
			category: def.category || 'learnomy',
			icon: def.icon || 'screenoptions',
			attributes: attributes,
			supports: { html: false },
			edit: editFor( def ),
			save: function () { return null; },
		} );
	} );
}( window.wp ) );
