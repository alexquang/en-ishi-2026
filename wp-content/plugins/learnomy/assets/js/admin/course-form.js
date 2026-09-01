/**
 * Course Form — handles create/edit submission, media library uploads,
 * collapsible sections, conditional fields, and searchable selects.
 *
 * Reads configuration from `window.learnomyAdmin`. Saves through the REST
 * API (`POST /courses` for create, `PATCH /courses/{id}` for update) —
 * the legacy `wp_ajax_learnomy_save_course` AJAX handler was removed in
 * Stage 5K-E.2; this file owns the form serialisation and is the only
 * consumer of those REST endpoints from the admin form.
 *
 * @package Learnomy
 */

( function () {
	'use strict';

	var __        = wp.i18n.__;
	var cfg       = window.learnomyAdmin || {};
	var restUrl   = cfg.restUrl || '/wp-json/learnomy/v1/';
	var restNonce = cfg.restNonce || '';
	var form      = document.getElementById( 'lrn-course-form' );

	if ( ! form ) {
		return;
	}

	var courseId   = parseInt( form.dataset.courseId, 10 ) || 0;
	var saveBtn    = document.getElementById( 'lrn-save-course' );
	var spinner    = form.querySelector( '.lrn-form-actions .spinner' );
	var uploadBtn  = document.getElementById( 'lrn-upload-thumbnail' );
	var thumbInput = document.getElementById( 'lrn-course-thumbnail' );

	// =========================================================================
	// Toast notification
	// =========================================================================

	function showToast( message, type ) {
		var existing = document.querySelector( '.lrn-toast' );
		if ( existing ) {
			existing.remove();
		}

		var toast = document.createElement( 'div' );
		toast.className = 'lrn-toast lrn-toast--' + ( type || 'success' );
		toast.textContent = message;
		document.body.appendChild( toast );

		toast.offsetHeight; // eslint-disable-line no-unused-expressions
		toast.classList.add( 'lrn-toast--visible' );

		setTimeout( function () {
			toast.classList.remove( 'lrn-toast--visible' );
			setTimeout( function () {
				toast.remove();
			}, 300 );
		}, 4000 );
	}

	// =========================================================================
	// Collapsible sections — click the header to toggle .is-open
	// =========================================================================

	form.querySelectorAll( '.lrn-settings-section__header' ).forEach( function ( header ) {
		header.addEventListener( 'click', function () {
			var section = header.closest( '.lrn-settings-section' );
			if ( ! section ) {
				return;
			}
			var isOpen = section.classList.toggle( 'is-open' );
			header.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
		} );
	} );

	// =========================================================================
	// Conditional visibility — [data-lrn-controls="<id>"] toggles
	// [data-lrn-conditional="<id>"] based on checkbox/select state.
	// =========================================================================

	function conditionMatches( control ) {
		if ( control.type === 'checkbox' ) {
			return control.checked;
		}
		if ( control.tagName === 'SELECT' ) {
			// Only the completion-percent case today — show when
			// completion_type === 'percentage'. Generalises by the data-attr.
			return control.value === 'percentage';
		}
		return Boolean( control.value );
	}

	function applyConditional( control ) {
		var id = control.getAttribute( 'data-lrn-controls' );
		if ( ! id ) {
			return;
		}
		form.querySelectorAll( '[data-lrn-conditional="' + id + '"]' ).forEach( function ( target ) {
			if ( conditionMatches( control ) ) {
				target.hidden = false;
			} else {
				target.hidden = true;
			}
		} );
	}

	form.querySelectorAll( '[data-lrn-controls]' ).forEach( function ( control ) {
		control.addEventListener( 'change', function () {
			applyConditional( control );
		} );
		// Apply current state on load so conditional sections are correct.
		applyConditional( control );
	} );

	// =========================================================================
	// Range slider → live value readout
	// =========================================================================

	form.querySelectorAll( 'input[type="range"][data-lrn-range-output]' ).forEach( function ( range ) {
		var outputId = range.getAttribute( 'data-lrn-range-output' );
		var output   = document.getElementById( outputId );
		if ( ! output ) {
			return;
		}
		function sync() {
			output.textContent = range.value + '%';
		}
		range.addEventListener( 'input', sync );
		sync();
	} );

	// =========================================================================
	// Searchable select — in-place filter of checkboxes or native options.
	// =========================================================================

	form.querySelectorAll( '[data-lrn-searchable-select]' ).forEach( function ( wrap ) {
		var input = wrap.querySelector( '[data-lrn-search-input]' );
		if ( ! input ) {
			return;
		}
		var items  = wrap.querySelectorAll( '[data-lrn-search-item]' );
		var native = wrap.querySelector( 'select.lrn-searchable-select__native' );
		var empty  = wrap.querySelector( '.lrn-searchable-select__empty' );

		input.addEventListener( 'input', function () {
			var term    = input.value.trim().toLowerCase();
			var visible = 0;

			items.forEach( function ( item ) {
				var text  = ( item.textContent || '' ).toLowerCase();
				var match = ! term || text.indexOf( term ) !== -1;
				item.hidden = ! match;
				if ( match ) {
					visible++;
				}
			} );

			// Native <select>: hide options via a synthetic empty-state.
			if ( native ) {
				Array.prototype.forEach.call( native.options, function ( opt ) {
					if ( ! opt.value ) {
						return;
					}
					var match = ! term || opt.textContent.toLowerCase().indexOf( term ) !== -1;
					opt.hidden = ! match;
					if ( match ) {
						visible++;
					}
				} );
			}

			if ( empty ) {
				empty.hidden = visible > 0;
			}
		} );
	} );

	// =========================================================================
	// Form submission
	// =========================================================================

	/**
	 * Build the JSON body for POST /courses or PATCH /courses/{id}.
	 *
	 * Top-level scalar columns (title, price, status, etc.) sit at the
	 * payload root. Toggle settings nest under `settings: {}` to match
	 * the REST controller's split between column writes and the
	 * settings_json bucket. Prerequisites and instructors are emitted
	 * alongside as REST-controller-specific shapes — the controller
	 * splits them off after extract.
	 */
	function gatherCoursePayload() {
		var payload = {};

		var scalars = [
			'title',
			'slug',
			'description',
			'short_description',
			'status',
			'access_type',
			'category_id',
			'thumbnail_url',
			'video_url',
			'completion_type',
			'completion_enforcement',
			'difficulty',
			// Per-course SEO overrides. This list is an ALLOWLIST — a field
			// rendered in the form but missing here is serialized nowhere and
			// silently dropped on save, with the button still reporting success.
			// Adding a control to the form is therefore only half the work.
			'seo_title',
			'meta_description',
			'og_image_url',
			'og_image_id',
			'meta_robots',
		];
		scalars.forEach( function ( name ) {
			var el = form.querySelector( '[name="' + name + '"]' );
			if ( el ) {
				payload[ name ] = el.value;
			}
		} );

		// Date fields — REST schema validates `format: date-time` strictly,
		// so empty strings (the form's "no sale window" state) must be
		// omitted entirely rather than sent as ''. Append YYYY-MM-DD HH:MM:SS
		// suffix to make a parseable datetime out of the date input.
		var saleStartsEl = form.querySelector( '[name="sale_starts"]' );
		if ( saleStartsEl && saleStartsEl.value ) {
			payload.sale_starts = saleStartsEl.value + ' 00:00:00';
		}
		var saleEndsEl = form.querySelector( '[name="sale_ends"]' );
		if ( saleEndsEl && saleEndsEl.value ) {
			payload.sale_ends = saleEndsEl.value + ' 23:59:59';
		}

		// Numbers — coerce so the REST schema (`number` / `integer`) is
		// happy; empty strings are preserved as-is for nullable fields.
		var priceEl = form.querySelector( '[name="price"]' );
		if ( priceEl ) { payload.price = parseFloat( priceEl.value ) || 0; }

		// Always emit sale_price, even when the field is cleared — sending 0 for
		// an empty field lets the server clear the stored sale. Omitting it (the
		// old behaviour) meant a cleared sale price was never overwritten, so the
		// previous value "refilled" on reload (Basecamp #9981555911).
		var salePriceEl = form.querySelector( '[name="sale_price"]' );
		if ( salePriceEl ) {
			payload.sale_price = '' === salePriceEl.value ? 0 : ( parseFloat( salePriceEl.value ) || 0 );
		}

		// Pricing model + recurring fields — the partial wires these inputs
		// but the previous payload builder never emitted them. Without this,
		// picking "Recurring subscription" silently reset to free on save
		// (Basecamp #9925572434).
		//
		// Read the CHECKED radio, falling back to the first only when none is
		// checked. A single querySelector( ':checked, [name=...]' ) returns the
		// first element matching EITHER selector in document order — i.e. always
		// the first "Free" radio — so a non-default selection was dropped and
		// the server mirrored access_type back to free on save (#9936703405).
		// A DISABLED control must not contribute a value. `disabled` suppresses
		// native form submission, but querySelector happily returns disabled
		// elements, so the purchase lock — which disables every radio on a
		// course with paying customers — was still handing the server a model
		// to write (Basecamp 10171334397). The server refuses it now too; this
		// stops us asking in the first place.
		// THE FALLBACK IS GONE, and that is the fix. `|| querySelector(
		// '[name="pricing_model"]:not(:disabled)' )` returned the first ENABLED
		// radio in document order whenever the checked one was disabled or
		// nothing was checked — and the first radio is "Free". So a safety net
		// meant to cover "none is checked" actively answered the question wrong.
		//
		// Both states happen in normal use, with no interaction at all:
		//   - recurring is rendered checked+DISABLED until Stripe is connected
		//     (course-settings-pricing.php sets 'enabled' => $stripe_ready), so
		//     the first selector misses and the fallback said free;
		//   - a model whose module is off is not rendered at all, so NOTHING is
		//     checked and the fallback said free again.
		// Opening a $19/month course and pressing Update — changing nothing —
		// wrote pricing_model=free and access_type=free, orphaning
		// recurring_amount and giving the course away (Basecamp 10184487928).
		//
		// This line has now been corrected four times (#9925572434, #9936703405,
		// 10171334397, and this). Every previous fix refined WHICH element to
		// read; the actual lesson is that when the form cannot tell us the
		// model, the honest payload omits the key and lets the server keep what
		// it has. Guessing is what does the damage.
		var pricingModelEl = form.querySelector( '[name="pricing_model"]:checked:not(:disabled)' );
		if ( pricingModelEl && pricingModelEl.value ) {
			payload.pricing_model = pricingModelEl.value;
		} else {
			// access_type is a MIRROR of the same radio (course-pricing.js keeps
			// the hidden input in sync), so if the radio was unreadable the
			// mirror is a guess too — and the server derives pricing_model from
			// access_type when the explicit key is absent, which would turn
			// recurring into one_time instead of free. A quieter downgrade is
			// still a downgrade, so drop both keys together.
			delete payload.access_type;
		}

		var recurAmountEl = form.querySelector( '[name="recurring_amount"]' );
		if ( recurAmountEl && recurAmountEl.value !== '' ) {
			payload.recurring_amount = parseFloat( recurAmountEl.value ) || 0;
		}

		var recurIntervalEl = form.querySelector( '[name="recurring_interval"]' );
		if ( recurIntervalEl && recurIntervalEl.value ) {
			payload.recurring_interval = recurIntervalEl.value;
		}

		var accessDurEl = form.querySelector( '[name="access_duration_days"]' );
		if ( accessDurEl && accessDurEl.value !== '' ) {
			payload.access_duration_days = parseInt( accessDurEl.value, 10 ) || 0;
		}

		// Sale price must be strictly below the regular price — surface the
		// rule before the REST round-trip so the author gets instant feedback
		// instead of a generic save error. Server enforces the same rule.
		// (Basecamp #9925579263.)
		if (
			typeof payload.sale_price === 'number' && payload.sale_price > 0 &&
			typeof payload.price === 'number' && payload.sale_price >= payload.price
		) {
			showToast( __( 'Sale price must be lower than the regular price.', 'learnomy' ), 'error' );
			if ( salePriceEl && typeof salePriceEl.focus === 'function' ) {
				salePriceEl.focus();
			}
			return null;
		}

		// completion_value is conditional on completion_type=percentage; the
		// server handles nulling-out when the type isn't percentage.
		var completionValueEl = form.querySelector( '[name="completion_value"]' );
		if ( completionValueEl && completionValueEl.value !== '' ) {
			payload.completion_value = parseInt( completionValueEl.value, 10 ) || 0;
		}

		// Duration — minutes-and-hours UI rolls into a single seconds column.
		var hoursEl   = form.querySelector( '[name="duration_hours"]' );
		var minutesEl = form.querySelector( '[name="duration_minutes"]' );
		if ( hoursEl || minutesEl ) {
			var h = hoursEl ? Math.max( 0, parseInt( hoursEl.value, 10 ) || 0 ) : 0;
			var m = minutesEl ? Math.max( 0, Math.min( 59, parseInt( minutesEl.value, 10 ) || 0 ) ) : 0;
			payload.duration_seconds = ( h * 3600 ) + ( m * 60 );
		}

		// ── Settings (settings_json bucket) ──────────────────────────────
		var settings = {};

		var bools = [
			'certificate_enabled',
			'allow_retake',
			'reset_on_retake',
			'require_sequential',
			'reviews_enabled',
			'qa_enabled',
			'announcements_enabled',
			'comments_enabled',
			'is_featured',
		];
		// A form must not change a setting it does not show.
		//
		// This sent `false` for any control the form did not render, because a
		// missing element made `!!( el && el.checked )` false. `comments_enabled`
		// has no checkbox here at all — it is gated behind a has_action() with no
		// subscriber — so every course save silently turned lesson comments off
		// for that course (Basecamp 10199056673).
		//
		// From the owner's side that is inexplicable: they changed the title,
		// pressed Update, and a feature they never saw on the screen switched
		// off. Skipping absent controls means an unrendered setting keeps
		// whatever it had, and Course::update_settings() merges rather than
		// replaces, so nothing else has to know about it.
		//
		// The rule is deliberately about ALL of them, not just the one that
		// broke: any control that becomes conditional later is safe by default
		// instead of quietly writing false the first time it is hidden.
		bools.forEach( function ( name ) {
			var el = form.querySelector( '[name="' + name + '"]' );
			if ( ! el ) {
				return;
			}
			settings[ name ] = !! el.checked;
		} );

		// Three-way visibility select (public | unlisted | private). The server
		// derives the legacy is_private mirror from this, so the form no longer
		// sends is_private directly.
		var visibilityEl = form.querySelector( '[name="visibility"]' );
		if ( visibilityEl ) {
			settings.visibility = visibilityEl.value;
		}

		var certTemplateEl = form.querySelector( '[name="certificate_template_id"]' );
		if ( certTemplateEl ) {
			settings.certificate_template_id = parseInt( certTemplateEl.value, 10 ) || 0;
		}

		var minTimeEl = form.querySelector( '[name="min_time_minutes"]' );
		if ( minTimeEl ) {
			settings.min_time_seconds = Math.max( 0, parseInt( minTimeEl.value, 10 ) || 0 ) * 60;
		}

		[ 'language', 'target_audience', 'closed_cta_label', 'closed_cta_url' ].forEach( function ( name ) {
			var el = form.querySelector( '[name="' + name + '"]' );
			if ( el ) { settings[ name ] = el.value; }
		} );

		// Multi-line textarea → array of trimmed non-empty lines.
		var loEl = form.querySelector( '[name="learning_outcomes"]' );
		if ( loEl ) {
			settings.learning_outcomes = loEl.value
				.split( /\r\n|\r|\n/ )
				.map( function ( line ) { return line.trim(); } )
				.filter( function ( line ) { return line.length > 0; } );
		}

		// wp_editor textareas — pull latest from TinyMCE when attached.
		[ 'completion_message', 'welcome_message' ].forEach( function ( name ) {
			var el = form.querySelector( '[name="' + name + '"]' );
			if ( el ) {
				settings[ name ] = readEditorValue( el.id || name, el );
			}
		} );

		payload.settings = settings;

		// ── Prerequisites: array of course IDs from checked checkboxes ───
		var prereqEls       = form.querySelectorAll( 'input[name="prerequisites[]"]:checked' );
		payload.prerequisites = Array.prototype.map.call( prereqEls, function ( cb ) {
			return parseInt( cb.value, 10 ) || 0;
		} ).filter( function ( id ) { return id > 0; } );

		// ── Instructors: combine instructors[] + share_pct[<uid>] into the
		// REST controller's expected `[{ user_id, share_pct }]` shape. ONLY send
		// this when the editor actually renders the instructor control — the
		// roster + primary now live on the course-detail hub, and an
		// unconditional `instructors: []` here would make the controller wipe
		// every co-instructor on a normal course save.
		if ( form.querySelector( 'input[name="instructors[]"]' ) ) {
			var instructorEls = form.querySelectorAll( 'input[name="instructors[]"]:checked' );
			payload.instructors = Array.prototype.map.call( instructorEls, function ( cb ) {
				var uid     = parseInt( cb.value, 10 ) || 0;
				var shareEl = form.querySelector( 'input[name="share_pct[' + uid + ']"]' );
				var pct     = shareEl ? parseFloat( shareEl.value ) || 0 : 0;
				return { user_id: uid, share_pct: pct };
			} ).filter( function ( row ) { return row.user_id > 0; } );
		}

		// ── Primary instructor (= course author). Admin-only selector; the REST
		// controller reassigns author_id + moves is_primary, preserving co-instructors.
		var primaryEl = form.querySelector( '[name="primary_instructor_id"]' );
		if ( primaryEl && primaryEl.value ) {
			payload.primary_instructor_id = parseInt( primaryEl.value, 10 ) || 0;
		}

		// ── Taxonomy term IDs — tags plus any additional course taxonomy
		// registered via the `learnomy_course_taxonomies` filter all use the
		// same tag_ids[] checkboxes, so one collector covers them all.
		var tagEls = form.querySelectorAll( 'input[name="tag_ids[]"]:checked' );
		payload.tag_ids = Array.prototype.map.call( tagEls, function ( cb ) {
			return parseInt( cb.value, 10 ) || 0;
		} ).filter( function ( id ) { return id > 0; } );

		// ── Per-course commission override. Empty string = "inherit" (the
		// server clears the course rule so it falls back to instructor/global).
		var commissionEl = form.querySelector( '[name="commission_rate"]' );
		if ( commissionEl ) {
			payload.commission_rate = commissionEl.value;
		}

		// Integration fields — anything an extension printed via
		// learnomy_course_editor_fields inside a [data-lrn-ext-fields] panel.
		// Fields are namespaced under extension_fields[...] by the integration,
		// so merging at the payload root lands them at body.extension_fields —
		// the same place the quiz editor's generic serializer puts them. Read by
		// the learnomy_course_saved action via $request->get_json_params().
		if ( window.lrnExtFields ) {
			Object.assign( payload, window.lrnExtFields.collect() );
		}

		return payload;
	}

	/**
	 * wp_editor instances lazy-attach to the underlying textarea; when
	 * TinyMCE is active, el.value is stale until an editor save. Ask the
	 * editor registry for the latest HTML, fall back to the textarea.
	 */
	function readEditorValue( id, el ) {
		if ( typeof tinymce !== 'undefined' && tinymce.get ) {
			var editor = tinymce.get( id );
			if ( editor && ! editor.isHidden() ) {
				return editor.getContent();
			}
		}
		return el ? el.value : '';
	}

	function setLoading( loading ) {
		saveBtn.disabled = loading;

		if ( spinner ) {
			spinner.classList.toggle( 'is-active', loading );
		}
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		// Sync the WordPress rich-text editor (TinyMCE) back to its textarea so
		// gatherCoursePayload() picks up the latest description. Without this
		// the textarea holds the pre-edit value and the formatted content is
		// stranded inside the iframe; on submit the request would overwrite
		// the description with stale text.
		if ( window.tinymce && window.tinymce.get && window.tinymce.get( 'lrn-course-desc' ) ) {
			window.tinymce.get( 'lrn-course-desc' ).save();
		}

		var title = form.querySelector( '[name="title"]' );
		if ( ! title || ! title.value.trim() ) {
			showToast( __( 'Course title is required.', 'learnomy' ), 'error' );
			if ( title ) {
				title.focus();
			}
			return;
		}

		// gatherCoursePayload() returns null when client-side validation fails
		// (e.g. sale_price ≥ price). It already showed the toast + focused the
		// offending input, so just bail without setLoading or fetch.
		var payloadBody = gatherCoursePayload();
		if ( null === payloadBody ) {
			return;
		}

		setLoading( true );

		var method = courseId > 0 ? 'PATCH' : 'POST';
		var path   = courseId > 0 ? 'courses/' + courseId : 'courses';

		fetch( restUrl + path, {
			method:      method,
			credentials: 'same-origin',
			headers:     {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   restNonce,
			},
			body: JSON.stringify( payloadBody ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( payload ) {
					return { ok: response.ok, payload: payload };
				} );
			} )
			.then( function ( out ) {
				setLoading( false );

				if ( ! out.ok ) {
					var msg = ( out.payload && out.payload.error && out.payload.error.message )
						|| ( out.payload && out.payload.message )
						|| __( 'Could not save the course.', 'learnomy' );
					showToast( msg, 'error' );
					return;
				}

				var savedRow    = ( out.payload && out.payload.data ) || {};
				var savedStatus = savedRow.status || '';
				var statusEl    = form.querySelector( '[name="status"]' );

				// A requested status is a request, not an outcome. The site's
				// self-publish policy can route an instructor's publish into
				// the approval queue, and the form must say so instead of
				// leaving the Status select reading "Published" over a row
				// that is awaiting review (Basecamp 10132217506).
				var statusDiverged = !! ( statusEl && savedStatus && statusEl.value !== savedStatus );

				if ( statusDiverged ) {
					statusEl.value = savedStatus;
					showToast(
						'pending_review' === savedStatus
							? __( 'Saved and submitted for review. An administrator has to approve this course before it goes live.', 'learnomy' )
							: __( 'Saved. This site does not allow you to set that status, so the course kept its current one.', 'learnomy' ),
						'success'
					);
				} else {
					showToast( courseId > 0 ? __( 'Course updated.', 'learnomy' ) : __( 'Course created.', 'learnomy' ), 'success' );
				}

				// New course → redirect to its edit URL so subsequent saves
				// land on the update path (matches the legacy AJAX
				// "redirect after create" behaviour).
				var newId = savedRow.id ? parseInt( savedRow.id, 10 ) : 0;
				if ( ! courseId && newId > 0 ) {
					// Hold the redirect when the server changed the status, or
					// the one message explaining why would be destroyed by the
					// page load a few milliseconds later.
					var editUrl = 'admin.php?page=learnomy-edit-course&course_id=' + newId + '&saved=1';
					if ( statusDiverged ) {
						setTimeout( function () {
							window.location.href = editUrl;
						}, 3000 );
					} else {
						window.location.href = editUrl;
					}
					return;
				}

				if ( courseId && saveBtn ) {
					var original       = saveBtn.textContent;
					saveBtn.textContent = __( 'Saved', 'learnomy' );
					setTimeout( function () {
						saveBtn.textContent = original;
					}, 2000 );
				}
			} )
			.catch( function ( err ) {
				setLoading( false );
				showToast( ( err && err.message ) || __( 'Network error. Please try again.', 'learnomy' ), 'error' );
			} );
	} );

	// =========================================================================
	// Media library integration
	// =========================================================================

	if ( uploadBtn && thumbInput ) {
		uploadBtn.addEventListener( 'click', function () {
			if ( typeof wp === 'undefined' || ! wp.media ) {
				return;
			}

			var frame = wp.media( {
				title:    __( 'Select Cover Image', 'learnomy' ),
				button:   { text: __( 'Use this image', 'learnomy' ) },
				multiple: false,
				library:  { type: 'image' },
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url        = attachment.url || '';

				thumbInput.value = url;

				var previewWrap = form.querySelector( '.lrn-course-form__thumbnail-preview' );
				if ( ! previewWrap ) {
					previewWrap = document.createElement( 'div' );
					previewWrap.className = 'lrn-course-form__thumbnail-preview';
					thumbInput.parentNode.appendChild( previewWrap );
				}

				while ( previewWrap.firstChild ) {
					previewWrap.removeChild( previewWrap.firstChild );
				}

				var img = document.createElement( 'img' );
				img.src     = url;
				img.alt     = '';
				img.loading = 'lazy';
				previewWrap.appendChild( img );
			} );

			frame.open();
		} );
	}
} )();
