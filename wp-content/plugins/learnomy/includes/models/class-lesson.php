<?php
/**
 * Lesson — individual learning unit inside a course section.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Lesson extends Model {

	/**
	 * Lessons belong to their course. Course_Service::delete() removes them via
	 * the section walk plus a sectionless sweep; this declares the same rule.
	 *
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'course' => 'course_id',
	);


	/** Canonical publish-status vocabulary — one word across every content entity. */
	public const STATUS_PUBLISHED = 'published';
	public const STATUS_DRAFT     = 'draft';

	/** Opt into the base Model publish-status failsafe (heals `publish` -> `published`). */
	protected static function normalizes_publish_status(): bool {
		return true;
	}

	/**
	 * `content_plain` is the tag-stripped half of the `ft_title_content` FULLTEXT
	 * index. It used to be derived in this model's create() and, separately, in
	 * the lessons + topics REST controllers — so `Lesson::update()` (admin saves,
	 * CLI, importers, Pro's builder) left the projection frozen at whatever the
	 * lesson said when it was first created. Declared here, insert AND update
	 * maintain it for every caller.
	 */
	protected static function search_projections(): array {
		return [ 'content' => 'content_plain' ];
	}

	/**
	 * Canonical list of lesson content types.
	 *
	 * Single source of truth for the `lrn_lessons.content_type` column — every
	 * admin form, REST whitelist, and Pro builder dropdown must read from this
	 * constant so the enum can never drift between surfaces.
	 *
	 * - text     : WYSIWYG body (default).
	 * - video    : External or uploaded video (uses `video_url`).
	 * - audio    : Audio-only lesson (uses `video_url` / audio URL).
	 * - pdf      : Downloadable or inline PDF.
	 * - embed    : Third-party embed (Slides, SoundCloud, etc.).
	 * - live     : Scheduled live session (Zoom/Meet/etc.).
	 * - download : Standalone downloadable asset (zip, ebook).
	 * - mediashield : A protected video served by the MediaShield plugin. The
	 *   `video_url` column holds the `mediashield_video` post ID (not a URL);
	 *   the player renders via the `[mediashield]` shortcode. Only selectable
	 *   when MediaShield is active.
	 */
	public const CONTENT_TYPES = [ 'text', 'video', 'audio', 'pdf', 'embed', 'live', 'download', 'mediashield' ];

	/**
	 * The selectable lesson content-types. Filterable so a Pro module can
	 * register an additional type (e.g. SCORM) without editing this list — the
	 * wp-admin editor dropdown, the Pro front-end builder, and the REST
	 * validation + schema enum all read through here, so a registered type is
	 * authorable AND valid across all three entry points. Values are coerced to
	 * sanitize_key-safe slugs.
	 *
	 * @return string[]
	 */
	public static function content_types(): array {
		/**
		 * Filter the selectable lesson content-types.
		 *
		 * @since 1.3.1
		 *
		 * @param string[] $types The built-in content-type slugs (CONTENT_TYPES).
		 */
		$types = (array) apply_filters( 'learnomy_lesson_content_types', self::CONTENT_TYPES );

		return array_values( array_unique( array_map( 'sanitize_key', $types ) ) );
	}

	/**
	 * The content types as DESCRIBED entries: slug + human label + icon hint.
	 *
	 * A native client cannot switch-case this list. It is filterable, Pro adds to
	 * it (SCORM/QTI/LTI/H5P), and an app binary already on a phone cannot learn a
	 * new case — so the Learnomy app drew the same fallback glyph for every type
	 * it had not been compiled with, including `pdf` and `embed`, which are built
	 * in. Describing the registry lets a client render a type it has never heard
	 * of, and lets a Pro module ship one without an app release.
	 *
	 * Built FROM content_types() rather than beside it, so the describable set and
	 * the authorable set cannot drift: a type registered on
	 * `learnomy_lesson_content_types` always appears here, and one that supplies
	 * no descriptor still gets a usable label rather than an empty cell.
	 *
	 * `icon` is a SEMANTIC hint ('video', 'audio', 'document'…), never a named
	 * icon from any particular set — the client maps it to whatever it draws with.
	 *
	 * @return array<int,array{slug:string,label:string,icon:string}>
	 */
	public static function content_type_descriptors(): array {
		// ICON HINTS ONLY. The label is not ours to invent: content_type_label()
		// already resolves it, cases the standards acronyms correctly (SCORM,
		// xAPI, LTI, QTI, H5P) even when the Pro extension that registers the
		// type is inactive, and applies `learnomy_lesson_content_type_label` so a
		// module can name its own type across every surface.
		//
		// A second label map here produced exactly the drift that filter exists
		// to prevent: H5P and SCORM both implement it, and both came out of this
		// method as "H5p" and "Scorm" — the app would have shown a worse name
		// than the web, from the same install.
		$icons = array(
			'text'        => 'document',
			'video'       => 'video',
			'audio'       => 'audio',
			'pdf'         => 'pdf',
			'embed'       => 'embed',
			'live'        => 'live',
			'download'    => 'download',
			'mediashield' => 'video',
			// The standards types are hinted HERE for the same reason Free labels
			// them here (LESSON-01): a client should draw them correctly even
			// when the Pro extension that registers the type is inactive, and a
			// package is not a document.
			'scorm'       => 'package',
			'xapi'        => 'package',
			'lti'         => 'external',
			'qti'         => 'quiz',
			'h5p'         => 'interactive',
		);

		$descriptors = array();
		foreach ( self::content_types() as $slug ) {
			$descriptors[] = array(
				'slug'  => $slug,
				'label' => self::content_type_label( $slug ),
				'icon'  => $icons[ $slug ] ?? 'document',
			);
		}

		/**
		 * Filter the described lesson content types.
		 *
		 * A module that registered a type on `learnomy_lesson_content_types`
		 * describes it here, so every client renders it correctly without
		 * shipping an update.
		 *
		 * @since 1.9.2
		 *
		 * @param array<int,array{slug:string,label:string,icon:string}> $descriptors Described types.
		 */
		return (array) apply_filters( 'learnomy_lesson_content_type_descriptors', $descriptors );
	}

	protected const BOOL_COLUMNS = [
		'require_scroll_end',
		'require_quiz_pass',
		'lock_next_until_complete',
		'comments_enabled',
		'is_preview',
	];

	protected const INT_COLUMNS = [
		'course_id',
		'section_id',
		'parent_id',
		'author_id',
		'duration_seconds',
		'min_time_seconds',
		'min_video_watch_pct',
		'sort_order',
		'drip_offset_days',
		'live_duration_minutes',
	];

	protected static function table_name(): string {
		return 'lessons';
	}

	/**
	 * Human-readable label for a lesson content type. Single source of truth so
	 * the curriculum list, catalog card format badge, and any future surface
	 * speak the same vocabulary.
	 *
	 * @param string $type One of CONTENT_TYPES (unknown types title-case as-is).
	 */
	public static function content_type_label( string $type ): string {
		$labels = array(
			'text'        => __( 'Text', 'learnomy' ),
			'video'       => __( 'Video', 'learnomy' ),
			'audio'       => __( 'Audio', 'learnomy' ),
			'pdf'         => __( 'PDF', 'learnomy' ),
			'embed'       => __( 'Embed', 'learnomy' ),
			'live'        => __( 'Live', 'learnomy' ),
			'download'    => __( 'Download', 'learnomy' ),
			'mediashield' => __( 'Video', 'learnomy' ),
			// Standards acronyms are cased correctly here (not ucfirst()) so
			// Free labels them right even when the Pro extension that registers
			// the type is inactive (LESSON-01). Pro may still override via the
			// learnomy_lesson_content_type_label filter below.
			'scorm'       => __( 'SCORM', 'learnomy' ),
			'xapi'        => __( 'xAPI', 'learnomy' ),
			'lti'         => __( 'LTI', 'learnomy' ),
			'qti'         => __( 'QTI', 'learnomy' ),
			'h5p'         => __( 'H5P', 'learnomy' ),
		);

		$label = $labels[ $type ] ?? ucfirst( $type );

		/**
		 * Filter the human-readable label for a lesson content-type, so a Pro
		 * module that registers a type (e.g. SCORM) can name it properly across
		 * every surface instead of falling back to ucfirst().
		 *
		 * @since 1.3.1
		 *
		 * @param string $label The resolved label.
		 * @param string $type  The content-type slug.
		 */
		return (string) apply_filters( 'learnomy_lesson_content_type_label', $label, $type );
	}

	/**
	 * Content types that are internal delivery TECH, not learner-facing formats.
	 *
	 * A student cares that a lesson is Text / Video / Audio / PDF — not that it
	 * is delivered via a SCORM package or an LTI launch. These standards names
	 * are admin classification; on learner surfaces they are hidden (LESSON-01).
	 */
	public const TECHNICAL_CONTENT_TYPES = array( 'scorm', 'xapi', 'lti', 'qti', 'h5p' );

	/**
	 * Learner-facing content-type label.
	 *
	 * Same as content_type_label() for the everyday formats, but returns an
	 * empty string for the technical/standards types — so a customer surface
	 * shows "a lesson", never "a SCORM lesson". Admin surfaces keep using
	 * content_type_label() to see the accurate classification.
	 *
	 * @param string $type Content-type slug.
	 * @return string Friendly label, or '' when the type is internal tech.
	 */
	public static function learner_content_type_label( string $type ): string {
		$hidden = (array) apply_filters( 'learnomy_technical_content_types', self::TECHNICAL_CONTENT_TYPES );
		if ( in_array( $type, $hidden, true ) ) {
			return '';
		}
		return self::content_type_label( $type );
	}

	/**
	 * Lucide icon name for a lesson content type. Pairs with content_type_label.
	 *
	 * @param string $type One of CONTENT_TYPES.
	 */
	public static function content_type_icon( string $type ): string {
		$icons = array(
			'text'        => 'file-text',
			'video'       => 'play',
			'audio'       => 'headphones',
			'pdf'         => 'file-text',
			'embed'       => 'code',
			'live'        => 'radio',
			'download'    => 'download',
			'mediashield' => 'play',
		);

		return $icons[ $type ] ?? 'file-text';
	}

	/**
	 * Create a lesson. `content_plain` (the FULLTEXT projection) is derived by the
	 * base Model from search_projections() above, on insert AND update.
	 */
	public static function create( array $lesson ): int {
		// Derive the slug from the title when none was supplied, then make it
		// unique within the course either way — an explicitly-provided slug
		// (e.g. the admin editing the URL) must still dedupe against siblings.
		$course_id = (int) ( $lesson['course_id'] ?? 0 );
		if ( empty( $lesson['slug'] ) && ! empty( $lesson['title'] ) ) {
			$lesson['slug'] = sanitize_title( $lesson['title'] );
		}
		if ( ! empty( $lesson['slug'] ) ) {
			$lesson['slug'] = static::unique_slug( $course_id, (string) $lesson['slug'] );
		}

		$defaults = [
			'sort_order'   => 0,
			'is_preview'   => 0,
			'status'       => 'published',
			'content_type' => 'text',
			'created_at'   => now(),
			'updated_at'   => now(),
		];
		$lesson   = array_merge( $defaults, $lesson );

		// Publish-status healing (publish -> published) is enforced uniformly in
		// Model::insert()/update() via normalizes_publish_status(), covering
		// every caller on create AND update.
		return static::insert( $lesson );
	}

	/**
	 * Look up a lesson by slug WITHOUT scoping to a course.
	 *
	 * DEPRECATED for view-data + router use after F-02.4 (2026-05-19). The
	 * router used to call this on /courses/<course-slug>/lesson/<lesson-slug>/
	 * and silently served the wrong course's lesson when two courses had
	 * matching lesson slugs. Always prefer {@see find_by_course_and_slug()}
	 * when the caller knows the course context (which is every view-data
	 * + router callsite by construction).
	 *
	 * Kept for legacy support (CLI inspection, admin search, etc.) — DO NOT
	 * use in any path that resolves a customer-facing URL.
	 */
	public static function find_by_slug( string $slug ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE slug = %s LIMIT 1',
				$slug
			)
		) ?: null;
	}

	/**
	 * Look up a lesson scoped to a specific course. Prefer this over
	 * {@see find_by_slug()} for any URL-driven resolution — the schema's
	 * UNIQUE (course_id, slug) (added by Migration 2.6.0) makes the
	 * lookup unambiguous and prevents the F-02.4 security bypass.
	 */
	public static function find_by_course_and_slug( int $course_id, string $slug ): ?object {
		if ( $course_id <= 0 || '' === $slug ) {
			return null;
		}
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE course_id = %d AND slug = %s LIMIT 1',
				$course_id,
				$slug
			)
		) ?: null;
	}

	/**
	 * Resolve a slug that is unique within a course, appending `-2`, `-3`… on
	 * collision. Scoped to the course (not global) to honour the schema's
	 * UNIQUE (course_id, slug) — see F-02.4. Pass `$exclude_id` so an update
	 * keeps the lesson's own current slug instead of bumping it to `-2`.
	 *
	 * @param int    $course_id  Course the lesson belongs to.
	 * @param string $desired    Raw desired slug (sanitised here).
	 * @param int    $exclude_id Lesson id to ignore when checking collisions.
	 * @return string Unique slug, or '' when $desired sanitises to empty.
	 */
	public static function unique_slug( int $course_id, string $desired, int $exclude_id = 0 ): string {
		$desired = sanitize_title( $desired );
		if ( '' === $desired ) {
			return '';
		}

		$base = $desired;
		$i    = 1;
		while ( true ) {
			$existing = static::find_by_course_and_slug( $course_id, $desired );
			if ( ! $existing || (int) $existing->id === $exclude_id ) {
				break;
			}
			$desired = $base . '-' . ( $i++ );
		}

		return $desired;
	}

	/**
	 * Build the public-facing URL for a lesson.
	 *
	 * Returns `null` when the lesson cannot be resolved to a public URL
	 * (missing or deleted parent course). The caller should omit the link
	 * rather than fall back to a generic /courses/ base URL.
	 *
	 * @param object      $lesson        Lesson row (must have `slug` and `course_id`).
	 * @param object|null $course   Parent course row if already fetched; otherwise
	 *                              fetched lazily. Pass explicitly to avoid an
	 *                              extra DB hit inside a loop.
	 * @return string|null          Full URL (trailing slash) or null.
	 */
	public static function get_permalink( object $lesson, ?object $course = null ): ?string {
		if ( empty( $lesson->slug ) || empty( $lesson->course_id ) ) {
			return null;
		}

		if ( null === $course ) {
			$course = Course::find( (int) $lesson->course_id );
		}

		if ( ! $course || empty( $course->slug ) ) {
			return null;
		}

		return \Learnomy\route_url(
			'lesson',
			array(
				'slug'        => (string) $course->slug,
				'lesson_slug' => (string) $lesson->slug,
			)
		);
	}

	/**
	 * Top-level lessons for a course in true curriculum order.
	 *
	 * `sort_order` is scoped WITHIN a section (1..n per section), so ordering by
	 * the lesson column alone interleaves lessons from different sections — the
	 * second lesson of section 1 sorts level with the second lesson of section 2.
	 * The sidebar (curriculum partial) renders section-by-section, so the flat
	 * navigation list (Continue-Learning, lesson auto-advance) drifted out of
	 * agreement with it (C-17 + C-22).
	 *
	 * We LEFT JOIN the parent section and order by the SECTION's sort_order
	 * first, then the lesson's own sort_order, then id as the final tiebreaker.
	 * LEFT JOIN keeps section-less lessons (flat courses, NULL section_id); their
	 * absent section sorts as 0 via the column's NULL ordering, so they keep
	 * their own lesson sort_order. This is the single source of truth every
	 * consumer (next-lesson, continue-url, completion counts, sidebar cursor)
	 * shares so navigation and the sidebar always agree.
	 *
	 * $limit defaults to 0 (unbounded) on purpose: the navigation / completion /
	 * sidebar-cursor consumers MUST see the whole ordered curriculum or they
	 * mis-count progress and pick the wrong next lesson. A list UI that only
	 * needs a page of rows passes an explicit $limit + $offset and pairs it with
	 * {@see count_for_course()} for the total. The course_section_sort index
	 * (course_id, section_id, sort_order) keeps the bounded query index-bound.
	 *
	 * $status defaults to 'published' — the guard lives HERE, in the model,
	 * because this method had 24+ callers and the customer-facing ones (progress
	 * denominator, Resume, REST curriculum, Pro SCORM/analytics) each leaked
	 * draft lessons independently when the filter was left to the call sites
	 * (Basecamp 10124162733: a draft in the denominator caps a student's
	 * progress below 100%, so the course never completes and no certificate is
	 * issued). Pass null for every status — that is for LIFECYCLE and EDITORIAL
	 * callers only (course-delete cascade, editor curriculum preview, admin drip
	 * list), never for anything a student's math or navigation touches.
	 *
	 * @param int         $course_id      Course whose top-level lessons to list.
	 * @param int         $limit          Max rows (0 = all).
	 * @param int         $offset         Rows to skip when paginating.
	 * @param string|null $status         Lesson status to include ('published' by
	 *                                    default), or null for every status.
	 * @param bool        $include_topics Also return sub-lessons (topics), ordered
	 *                                    parent-then-its-children. Default false so
	 *                                    every existing caller is untouched — see
	 *                                    the note in the branch below.
	 */
	public static function get_for_course( int $course_id, int $limit = 0, int $offset = 0, ?string $status = 'published', bool $include_topics = false ): array {
		$sections   = \Learnomy\table( 'sections' );
		$status_sql = null === $status ? '' : ' AND l.status = %s';
		$values     = [ $course_id ];

		if ( $include_topics ) {
			// Whole tree, ordered parent-then-its-own-topics.
			//
			// Self-join the parent so a topic sorts INSIDE its parent's slot: group
			// by the parent's (section, sort_order, id), then put the parent row
			// ahead of its children, then order the children among themselves.
			// Ordering by the topic's own sort_order alone would interleave topics
			// from different lessons, and ordering by id alone would ignore an
			// author's reordering entirely.
			//
			// Opt-in because the default MUST stay top-level-only: this method feeds
			// the progress denominator and certificate issuance (see above), and 24+
			// callers rely on that shape. Only a caller that renders the curriculum
			// tree — the mobile app's course screen — asks for topics.
			$lessons = static::table();
			$sql     = "SELECT l.* FROM {$lessons} AS l
			 LEFT JOIN {$lessons} AS p ON p.id = NULLIF( l.parent_id, 0 )
			 LEFT JOIN {$sections} AS s ON s.id = COALESCE( p.section_id, l.section_id )
			 WHERE l.course_id = %d{$status_sql}
			 ORDER BY s.sort_order ASC,
			          COALESCE( p.sort_order, l.sort_order ) ASC,
			          COALESCE( p.id, l.id ) ASC,
			          CASE WHEN COALESCE( l.parent_id, 0 ) = 0 THEN 0 ELSE 1 END ASC,
			          l.sort_order ASC,
			          l.id ASC";
			if ( null !== $status ) {
				$values[] = $status;
			}
		} else {
			$sql = 'SELECT l.* FROM ' . static::table() . " AS l
			 LEFT JOIN {$sections} AS s ON s.id = l.section_id
			 WHERE l.course_id = %d AND (l.parent_id IS NULL OR l.parent_id = 0){$status_sql}
			 ORDER BY s.sort_order ASC, l.sort_order ASC, l.id ASC";
			if ( null !== $status ) {
				$values[] = $status;
			}
		}

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$values[] = $limit;
			$values[] = max( 0, $offset );
		}

		$rows = static::db()->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			static::db()->prepare( $sql, ...$values )
		) ?: [];
		// scale C2: the curriculum walk re-fetches each of these by id (per-lesson
		// availability/drip checks) — prime find() so those become cache hits.
		static::prime_find_cache( $rows );
		return $rows;
	}

	/**
	 * Return the next top-level lesson in curriculum order after $lesson_id.
	 *
	 * Replaces the previous pattern of loading every lesson in the course
	 * (`get_for_course()`) and iterating the array to find the successor —
	 * which was O(N) in memory on every lesson-complete call. This query is O(1)
	 * (index-bound: course_id + section_id + sort_order) and reads only the
	 * single row that follows.
	 *
	 * The ordering mirrors {@see get_for_course()}: section.sort_order ASC,
	 * then lesson.sort_order ASC, then lesson.id ASC as the stable tiebreaker.
	 * Flat courses (no sections) sort entirely on lesson sort_order.
	 *
	 * @param int $lesson_id  Current lesson whose successor to find.
	 * @param int $course_id  Course scope (prevents cross-course drift).
	 * @return object|null    Next lesson row, or null at end of curriculum.
	 */
	public static function find_next_after( int $lesson_id, int $course_id ): ?object {
		if ( $lesson_id <= 0 || $course_id <= 0 ) {
			return null;
		}

		// Fetch the current lesson's position in the curriculum ordering.
		$sections = \Learnomy\table( 'sections' );
		$current  = static::db()->get_row(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT l.id, l.sort_order, COALESCE(s.sort_order, 0) AS section_sort, l.section_id
				 FROM ' . static::table() . " AS l
				 LEFT JOIN {$sections} AS s ON s.id = l.section_id
				 WHERE l.id = %d AND l.course_id = %d",
				$lesson_id,
				$course_id
			)
		);

		if ( ! $current ) {
			return null;
		}

		// Find the first lesson that sorts AFTER the current one using the same
		// compound key. A single row with LIMIT 1 is all we need. Published
		// only: this is pure student play-order — advancing into a draft hands
		// the student a lesson that 404s for them (the position lookup above
		// stays status-free on purpose, so an editor previewing FROM a draft
		// still resolves their place in the sequence).
		return static::db()->get_row(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT l.* FROM ' . static::table() . " AS l
				 LEFT JOIN {$sections} AS s ON s.id = l.section_id
				 WHERE l.course_id = %d
				   AND (l.parent_id IS NULL OR l.parent_id = 0)
				   AND l.status = 'published'
				   AND (
				     COALESCE(s.sort_order, 0) > %d
				     OR (
				       COALESCE(s.sort_order, 0) = %d
				       AND (l.sort_order > %d OR (l.sort_order = %d AND l.id > %d))
				     )
				   )
				 ORDER BY COALESCE(s.sort_order, 0) ASC, l.sort_order ASC, l.id ASC
				 LIMIT 1",
				$course_id,
				(int) $current->section_sort,
				(int) $current->section_sort,
				(int) $current->sort_order,
				(int) $current->sort_order,
				$lesson_id
			)
		) ?: null;
	}

	/**
	 * Next unit in the FLATTENED curriculum sequence after $lesson_id.
	 *
	 * Sub-lessons (child rows, `parent_id` set) are standalone player pages, so
	 * the play order walks: parent lesson → each of its sub-lessons in order →
	 * the next top-level lesson. {@see find_next_after()} only ever returns
	 * top-level lessons, so using it alone made completing a parent lesson jump
	 * straight past its own sub-lessons. This walker descends into sub-lessons
	 * and resumes at the next top-level lesson once they are exhausted.
	 *
	 * @param int $lesson_id  Current lesson or sub-lesson.
	 * @param int $course_id  Course scope.
	 * @return object|null    Next unit in play order, or null at end of course.
	 */
	public static function find_next_in_sequence( int $lesson_id, int $course_id ): ?object {
		if ( $lesson_id <= 0 || $course_id <= 0 ) {
			return null;
		}

		$current = static::find( $lesson_id );
		if ( ! $current || (int) $current->course_id !== $course_id ) {
			return null;
		}

		$parent_id = (int) ( $current->parent_id ?? 0 );

		if ( $parent_id > 0 ) {
			// On a sub-lesson: advance to the next sibling sub-lesson, else to
			// the next top-level lesson after the shared parent.
			$next_sibling = static::find_next_sub_lesson( $lesson_id, $parent_id );
			return $next_sibling ?: static::find_next_after( $parent_id, $course_id );
		}

		// On a top-level lesson: descend into its first sub-lesson if it has
		// any, otherwise advance to the next top-level lesson.
		return static::first_sub_lesson( $lesson_id ) ?: static::find_next_after( $lesson_id, $course_id );
	}

	/**
	 * First sub-lesson (child row) of a parent lesson in sort order, or null.
	 */
	public static function first_sub_lesson( int $parent_id ): ?object {
		if ( $parent_id <= 0 ) {
			return null;
		}

		// Published only — play-order navigation must never descend into a
		// draft topic (same rule as find_next_after()).
		return static::db()->get_row(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . static::table() . " WHERE parent_id = %d AND status = 'published' ORDER BY sort_order ASC, id ASC LIMIT 1",
				$parent_id
			)
		) ?: null;
	}

	/**
	 * Next sibling sub-lesson after $sub_lesson_id under the same parent, or null.
	 */
	public static function find_next_sub_lesson( int $sub_lesson_id, int $parent_id ): ?object {
		if ( $sub_lesson_id <= 0 || $parent_id <= 0 ) {
			return null;
		}

		$current = static::db()->get_row(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT id, sort_order FROM ' . static::table() . ' WHERE id = %d AND parent_id = %d',
				$sub_lesson_id,
				$parent_id
			)
		);
		if ( ! $current ) {
			return null;
		}

		// Published only — same play-order rule as find_next_after().
		return static::db()->get_row(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . static::table() . " WHERE parent_id = %d AND status = 'published' AND ( sort_order > %d OR ( sort_order = %d AND id > %d ) ) ORDER BY sort_order ASC, id ASC LIMIT 1",
				$parent_id,
				(int) $current->sort_order,
				(int) $current->sort_order,
				$sub_lesson_id
			)
		) ?: null;
	}

	/**
	 * Flat, ordered curriculum sequence for a course: every top-level lesson
	 * immediately followed by its sub-lessons in order. This is the canonical
	 * play order the lesson player walks for prev/next navigation, so a sub-
	 * lesson page resolves real neighbours instead of falling off the top-level
	 * list (which left prev/next blank on every sub-lesson). Two queries total
	 * (parents + batched children); bounded by one course's lesson count.
	 *
	 * @return object[]
	 */
	public static function get_curriculum_sequence( int $course_id ): array {
		if ( $course_id <= 0 ) {
			return [];
		}

		$parents = static::get_for_course( $course_id );
		if ( ! $parents ) {
			return [];
		}

		$children_map = static::get_topics_for_parents(
			array_map( static fn( $parent ) => (int) $parent->id, $parents )
		);

		$sequence = [];
		foreach ( $parents as $parent ) {
			$sequence[] = $parent;
			foreach ( $children_map[ (int) $parent->id ] ?? [] as $child ) {
				$sequence[] = $child;
			}
		}

		return $sequence;
	}

	/**
	 * Total top-level lessons in a course. Companion to {@see get_for_course()}
	 * so a paginated list UI can render "Showing 50 of N" without pulling every
	 * row just to count them. Applies the identical WHERE clause — including
	 * the same 'published' status default — so a paginated total can never
	 * disagree with the rows.
	 *
	 * @param int         $course_id      Course to count.
	 * @param string|null $status         Lesson status to count ('published' by
	 *                                    default), or null for every status.
	 * @param bool        $include_topics Count sub-lessons too. MUST mirror the
	 *                                    flag passed to get_for_course(), or the
	 *                                    "N of M" a paginated client renders
	 *                                    disagrees with the rows it was handed.
	 */
	public static function count_for_course( int $course_id, ?string $status = 'published', bool $include_topics = false ): int {
		$status_sql = null === $status ? '' : ' AND status = %s';
		$parent_sql = $include_topics ? '' : ' AND (parent_id IS NULL OR parent_id = 0)';
		$values     = [ $course_id ];
		if ( null !== $status ) {
			$values[] = $status;
		}
		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT COUNT(*) FROM ' . static::table() . " WHERE course_id = %d{$parent_sql}{$status_sql}",
				...$values
			)
		);
	}

	/**
	 * Fetch every relative-drip lesson in a course.
	 *
	 * Used by the `learnomy_student_enrolled` listener to compute per-student
	 * unlock rows. Returns id + drip_offset_days only — that's everything the
	 * listener needs to insert into `lrn_lesson_unlocks`. Includes both
	 * top-level lessons and topics; topics inherit their parent lesson's
	 * course_id so the WHERE clause already covers them.
	 *
	 * @return object[] Rows with `id` (int) and `drip_offset_days` (int|null).
	 */
	public static function get_relative_drip_for_course( int $course_id ): array {
		if ( $course_id <= 0 ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT id, drip_offset_days FROM ' . static::table() . "
				 WHERE course_id = %d
				 AND drip_mode = 'relative'
				 AND drip_offset_days IS NOT NULL",
				$course_id
			)
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Fixed-date drip lessons whose available_date falls inside the given UTC window.
	 *
	 * Excludes relative-drip rows (drip_mode = 'relative') so the row-level
	 * `available_date` cannot accidentally double-fire for relative schedules.
	 * Called by Drip_Cron to find lessons that became available since the last tick.
	 *
	 * @return object[] Rows with `id`, `course_id`, and `available_date`.
	 */
	public static function get_fixed_drip_in_window( string $from_utc, string $to_utc ): array {
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT id, course_id, available_date FROM ' . static::table() . "
				 WHERE available_date IS NOT NULL
				 AND drip_mode != 'relative'
				 AND available_date > %s
				 AND available_date <= %s",
				$from_utc,
				$to_utc
			)
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * All lessons in a course that have a non-null available_date, ordered
	 * chronologically. Used by the CLI drip-preview command to tabulate
	 * upcoming unlocks without processing them.
	 *
	 * @return object[] Rows with `id`, `title`, and `available_date`.
	 */
	public static function get_drip_scheduled_for_course( int $course_id ): array {
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT id, title, available_date FROM ' . static::table() . '
				 WHERE course_id = %d
				 AND available_date IS NOT NULL
				 ORDER BY available_date ASC',
				$course_id
			)
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Top-level lessons for a section, in curriculum order.
	 *
	 * $limit defaults to 0 (unbounded) for internal callers like the view-data
	 * resolver and the section-lessons batch helper that must see the full list.
	 * The REST endpoint passes an explicit $limit + $offset and pairs it with
	 * {@see count_for_section()} for the total.
	 *
	 * @param int         $section_id Section whose lessons to list.
	 * @param int         $limit      Max rows (0 = all).
	 * @param int         $offset     Rows to skip when paginating.
	 * @param string|null $status     Lesson status ('published' by default), or
	 *                                null for every status (editorial callers:
	 *                                admin pickers, builder, delete cascades).
	 */
	public static function get_for_section( int $section_id, int $limit = 0, int $offset = 0, ?string $status = 'published' ): array {
		$status_sql = null === $status ? '' : ' AND status = %s';
		$sql        = 'SELECT * FROM ' . static::table() . " WHERE section_id = %d AND (parent_id IS NULL OR parent_id = 0){$status_sql} ORDER BY sort_order ASC";
		$values     = [ $section_id ];
		if ( null !== $status ) {
			$values[] = $status;
		}

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$values[] = $limit;
			$values[] = max( 0, $offset );
		}

		return static::db()->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			static::db()->prepare( $sql, ...$values )
		) ?: [];
	}

	/**
	 * Top-level lessons in a course that belong to NO section (section_id NULL
	 * or 0). Sections are optional, so these "ungrouped" lessons are first-class
	 * and must render in both the course builder and the frontend curriculum —
	 * not be silently hidden because no section bucket holds them.
	 *
	 * @param int         $course_id Course.
	 * @param string|null $status    Lesson status ('published' by default), or
	 *                               null for every status (editorial callers).
	 * @return object[] Top-level section-less lesson rows, in sort order.
	 */
	public static function get_section_less_for_course( int $course_id, ?string $status = 'published' ): array {
		$status_sql = null === $status ? '' : ' AND status = %s';
		$sql        = 'SELECT * FROM ' . static::table()
			. ' WHERE course_id = %d AND ( section_id IS NULL OR section_id = 0 )'
			. " AND ( parent_id IS NULL OR parent_id = 0 ){$status_sql} ORDER BY sort_order ASC, id ASC";
		$values     = [ $course_id ];
		if ( null !== $status ) {
			$values[] = $status;
		}

		return static::db()->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			static::db()->prepare( $sql, ...$values )
		) ?: [];
	}

	/**
	 * Total top-level lessons in a section. Companion to {@see get_for_section()}
	 * for paginated REST responses — runs a single COUNT(*) instead of loading
	 * all rows just to call count() on them.
	 */
	public static function count_for_section( int $section_id, ?string $status = 'published' ): int {
		$status_sql = null === $status ? '' : ' AND status = %s';
		$values     = [ $section_id ];
		if ( null !== $status ) {
			$values[] = $status;
		}
		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT COUNT(*) FROM ' . static::table() . " WHERE section_id = %d AND (parent_id IS NULL OR parent_id = 0){$status_sql}",
				...$values
			)
		);
	}

	/**
	 * Batch version of {@see get_for_section()} — fetch every top-level lesson
	 * for a set of section ids in ONE query and return a `section_id => [lessons]`
	 * map. Avoids the N+1 the course outline would otherwise run (one
	 * get_for_section() per section). Sections with no lessons are absent from
	 * the map (caller defaults []). Per-section order matches get_for_section():
	 * sort_order ASC, with id as a stable tiebreak.
	 *
	 * @param int[]       $section_ids
	 * @param string|null $status Lesson status ('published' by default), or null
	 *                            for every status.
	 * @return array<int,object[]>
	 */
	public static function get_for_sections( array $section_ids, ?string $status = 'published' ): array {
		$section_ids = array_values( array_unique( array_filter( array_map( 'intval', $section_ids ) ) ) );
		if ( ! $section_ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $section_ids ), '%d' ) );
		$status_sql   = null === $status ? '' : ' AND status = %s';
		$values       = $section_ids;
		if ( null !== $status ) {
			$values[] = $status;
		}
		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . static::table() . " WHERE section_id IN ({$placeholders}) AND (parent_id IS NULL OR parent_id = 0){$status_sql} ORDER BY section_id ASC, sort_order ASC, id ASC",
				...$values
			)
		) ?: array();

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row->section_id ][] = $row;
		}
		return $map;
	}

	/**
	 * Top-level (non-topic) lessons for a course that live inside a section,
	 * grouped by section_id. ONE query for the whole course, keyed on the
	 * course_id-leading `course_section_sort (course_id, section_id, sort_order)`
	 * index — so the admin course-builder tree neither runs a per-section query
	 * (the old N+1) nor degrades to a section_id-only scan the composite index
	 * can't serve. The builder loads the whole curriculum anyway, so scoping by
	 * course_id is both correct and the fastest index path.
	 *
	 * @return array<int, array<int, object>> section_id => rows[]
	 */
	public static function top_level_for_course_by_section( int $course_id ): array {
		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . static::table() . ' WHERE course_id = %d AND section_id > 0 AND (parent_id IS NULL OR parent_id = 0) ORDER BY section_id ASC, sort_order ASC, id ASC',
				$course_id
			)
		) ?: array();

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row->section_id ][] = $row;
		}
		return $map;
	}

	/** Reorder lessons within a section by applying sort positions from the given array. */
	public static function reorder( int $section_id, array $order ): void {
		$table = static::table();
		foreach ( $order as $position => $lesson_id ) {
			static::db()->update(
				$table,
				[ 'sort_order' => (int) $position ],
				[
					'id'         => (int) $lesson_id,
					'section_id' => $section_id,
				],
				[ '%d' ],
				[ '%d', '%d' ]
			);
		}
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
	}

	/**
	 * Flat picker listing used by admin dependent dropdowns.
	 *
	 * Caller supplies scope filters — we enforce at least one so callers can't
	 * accidentally spill every lesson across every course. Returns the minimum
	 * fields needed to render a `<select>`.
	 *
	 * The result is capped (default 50) so a picker against a 5k-lesson course
	 * never streams every row into the modal. The caller pairs it with
	 * {@see count_for_picker()} to report the true total and offer paging.
	 *
	 * @param array{course_id?: int, section_id?: int, parent_only?: bool, unattached_to_course?: int, search?: string} $filters
	 * @param int                                                                                                       $limit   Max rows (default 50; 0 = all).
	 * @param int                                                                                                       $offset  Rows to skip when paginating.
	 * @return object[] Each row has id, title, slug, course_id, section_id, parent_id, sort_order, status.
	 */
	public static function list_for_picker( array $filters, int $limit = 50, int $offset = 0 ): array {
		[ $conditions, $values ] = static::picker_conditions( $filters );

		if ( empty( $conditions ) ) {
			return [];
		}

		$table = static::table();
		$sql   = "SELECT id, title, slug, course_id, section_id, parent_id, sort_order, status FROM {$table} WHERE " . implode( ' AND ', $conditions ) . ' ORDER BY sort_order ASC, id ASC';

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$values[] = $limit;
			$values[] = max( 0, $offset );
		}

		if ( empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = static::db()->get_results( $sql );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = static::db()->get_results( static::db()->prepare( $sql, ...$values ) );
		}

		return $rows ?: [];
	}

	/**
	 * Total rows {@see list_for_picker()} would match for the same filters,
	 * ignoring limit/offset. Lets the picker REST endpoint report the real
	 * total instead of mistaking the page size for the count.
	 *
	 * @param array{course_id?: int, section_id?: int, parent_only?: bool, unattached_to_course?: int, search?: string} $filters
	 */
	public static function count_for_picker( array $filters ): int {
		[ $conditions, $values ] = static::picker_conditions( $filters );

		if ( empty( $conditions ) ) {
			return 0;
		}

		$table = static::table();
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $conditions );

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = static::db()->prepare( $sql, ...$values );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) static::db()->get_var( $sql );
	}

	/**
	 * Build the shared WHERE conditions + bound values for the picker so
	 * {@see list_for_picker()} and {@see count_for_picker()} can never drift
	 * out of agreement (the list page and its total must filter identically).
	 *
	 * @param array<string,mixed> $filters
	 * @return array{0: string[], 1: array<int,mixed>} [ conditions, values ]
	 */
	private static function picker_conditions( array $filters ): array {
		$conditions = [];
		$values     = [];

		if ( ! empty( $filters['course_id'] ) ) {
			$conditions[] = 'course_id = %d';
			$values[]     = (int) $filters['course_id'];
		}
		if ( ! empty( $filters['section_id'] ) ) {
			$conditions[] = 'section_id = %d';
			$values[]     = (int) $filters['section_id'];
		}
		if ( ! empty( $filters['parent_only'] ) ) {
			$conditions[] = '(parent_id IS NULL OR parent_id = 0)';
		}
		// `unattached_to_course` is the Course Builder "Attach Existing"
		// modal's primary use case — find lessons that exist somewhere but
		// aren't already inside this course. Passed as the course id to
		// exclude (the course currently being edited). Without this flag,
		// the Attach Existing search returned only lessons already on the
		// course, so the modal looked empty (bug card #7).
		if ( ! empty( $filters['unattached_to_course'] ) ) {
			$conditions[] = '(course_id IS NULL OR course_id = 0 OR course_id != %d)';
			$values[]     = (int) $filters['unattached_to_course'];
		}
		// Title substring match for the picker's search input. Empty input
		// passes through unfiltered so the modal can still browse candidates.
		if ( ! empty( $filters['search'] ) ) {
			$conditions[] = 'title LIKE %s';
			$values[]     = '%' . static::db()->esc_like( (string) $filters['search'] ) . '%';
		}

		return [ $conditions, $values ];
	}

	// Topics are sub-lessons — child rows where parent_id points to a lesson.
	// 'published' by default (student surfaces); editorial callers (admin
	// lessons list, builder, delete cascade, reorder) pass null for all.
	public static function get_topics( int $lesson_id, ?string $status = 'published' ): array {
		$status_sql = null === $status ? '' : ' AND status = %s';
		$values     = [ $lesson_id ];
		if ( null !== $status ) {
			$values[] = $status;
		}
		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . static::table() . " WHERE parent_id = %d{$status_sql} ORDER BY sort_order ASC",
				...$values
			)
		) ?: [];
	}

	/**
	 * The batched twin of {@see get_for_course()} — the ordered curriculum for a
	 * SET of courses in ONE query.
	 *
	 * Ordering, filtering and semantics are IDENTICAL to get_for_course() by
	 * design: same LEFT JOIN to sections, same
	 * `s.sort_order, l.sort_order, l.id` order, same top-level-only filter. Any
	 * divergence would be a second definition of "the curriculum", and a
	 * per-lesson funnel compared in a different order than the one learners
	 * actually walk reports the wrong lesson as the drop-off point. Adding
	 * `l.course_id` as the leading sort key only groups the result; it does not
	 * change any single course's internal order.
	 *
	 * Exists because reporting needs the outline for a whole page of courses at
	 * once — looping get_for_course() turns a 20-course page into 20 queries for
	 * a value shown in one column.
	 *
	 * @param int[] $course_ids Courses to fetch lessons for.
	 * @return object[] Lesson rows, course-major then curriculum order.
	 */
	public static function for_courses( array $course_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$sections     = \Learnomy\table( 'sections' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names + generated int placeholders.
				'SELECT l.* FROM ' . static::table() . " AS l
				 LEFT JOIN {$sections} AS s ON s.id = l.section_id
				 WHERE l.course_id IN ({$placeholders})
				   AND ( l.parent_id IS NULL OR l.parent_id = 0 )
				   AND l.status = 'published'
				 ORDER BY l.course_id ASC, s.sort_order ASC, l.sort_order ASC, l.id ASC",
				...$ids
			)
		) ?: array();
	}

	/**
	 * Batch version of {@see get_topics()} — fetch every sub-lesson for a set of
	 * parent ids in ONE query and return a `parent_id => [children]` map. Avoids
	 * the N+1 the admin Lessons list would otherwise run (one get_topics() per
	 * row). Parents with no topics are absent from the map (caller defaults []).
	 *
	 * @param int[]       $parent_ids
	 * @param string|null $status Lesson status ('published' by default), or null
	 *                            for every status (admin lessons list).
	 * @return array<int,object[]>
	 */
	public static function get_topics_for_parents( array $parent_ids, ?string $status = 'published' ): array {
		$parent_ids = array_values( array_unique( array_filter( array_map( 'intval', $parent_ids ) ) ) );
		if ( ! $parent_ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );
		$status_sql   = null === $status ? '' : ' AND status = %s';
		$values       = $parent_ids;
		if ( null !== $status ) {
			$values[] = $status;
		}
		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . static::table() . " WHERE parent_id IN ({$placeholders}){$status_sql} ORDER BY parent_id ASC, sort_order ASC",
				...$values
			)
		) ?: array();

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row->parent_id ][] = $row;
		}
		return $map;
	}

	/**
	 * Paginated search for top-level lessons with optional status / search /
	 * course filters.
	 *
	 * @return array{items: array, total: int}
	 */
	public static function search_paginated( string $status = 'all', string $search = '', int $per_page = 20, int $page = 1, int $course_id = 0, int $tag_id = 0, int $author_id = 0, ?array $scope = null ): array {
		$table        = static::table();
		$where_values = [];

		// Wrap the parent-id OR in parens so it can't dissolve the AND chain.
		$where_clauses = [ '(l.parent_id IS NULL OR l.parent_id = 0)' ];

		if ( 'all' !== $status ) {
			$where_clauses[] = 'l.status = %s';
			$where_values[]  = $status;
		}

		// Instructor scope. `$scope` is the OR predicate from
		// Permission_Engine::instructor_scope_sql() — lessons this user
		// authored, plus lessons on a course they manage — and it supersedes
		// the older author-only form. An admin passes null for both and sees
		// everything. `$author_id` is kept for callers that genuinely want one
		// author's lessons regardless of course (the REST list does).
		if ( is_array( $scope ) && isset( $scope[0], $scope[1] ) ) {
			$where_clauses[] = (string) $scope[0];
			$where_values    = array_merge( $where_values, array_values( (array) $scope[1] ) );
		} elseif ( $author_id > 0 ) {
			$where_clauses[] = 'l.author_id = %d';
			$where_values[]  = $author_id;
		}

		if ( $course_id > 0 ) {
			$where_clauses[] = 'l.course_id = %d';
			$where_values[]  = $course_id;
		}

		if ( $search ) {
			$like            = '%' . static::db()->esc_like( $search ) . '%';
			$where_clauses[] = '(l.title LIKE %s OR l.content_plain LIKE %s)';
			$where_values[]  = $like;
			$where_values[]  = $like;
		}

		// Tag filter — JOIN the tag-map on the lesson assignment. The
		// (object_id, object_type) pair is the map's natural lookup key, so the
		// join stays index-friendly at scale.
		$join_sql = '';
		if ( $tag_id > 0 ) {
			$map          = static::db()->prefix . 'lrn_tag_map';
			$join_sql     = " INNER JOIN {$map} AS tm ON tm.object_id = l.id AND tm.object_type = 'lesson' AND tm.tag_id = %d";
			$where_values = array_merge( [ $tag_id ], $where_values );
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(DISTINCT l.id) FROM {$table} AS l{$join_sql} WHERE {$where_sql}";
		if ( ! empty( $where_values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$count_sql = static::db()->prepare( $count_sql, ...$where_values );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) static::db()->get_var( $count_sql );

		// Fetch.
		$offset = ( max( 1, $page ) - 1 ) * $per_page;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query_sql    = "SELECT l.* FROM {$table} AS l{$join_sql} WHERE {$where_sql} ORDER BY l.created_at DESC LIMIT %d OFFSET %d";
		$query_values = array_merge( $where_values, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$items = static::db()->get_results( static::db()->prepare( $query_sql, ...$query_values ) ) ?: [];

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/** FULLTEXT search on lessons whose parent course is published. */
	public static function fulltext_search( string $query, int $limit = 20 ): array {
		$courses_table = \Learnomy\table( 'courses' );
		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT l.id, l.title, l.content_plain, l.slug, l.course_id,
				        c.slug AS course_slug, c.title AS course_title,
				        c.access_type AS course_access_type,
				        MATCH(l.title, l.content_plain) AGAINST(%s IN NATURAL LANGUAGE MODE) AS relevance
				 FROM ' . static::table() . " AS l
				 INNER JOIN {$courses_table} AS c ON c.id = l.course_id AND c.status = 'published'
				 WHERE MATCH(l.title, l.content_plain) AGAINST(%s IN NATURAL LANGUAGE MODE)
				 ORDER BY relevance DESC
				 LIMIT %d",
				$query,
				$query,
				$limit
			)
		) ?: [];
	}

	/** Count of lessons matching a FULLTEXT query (with published course filter). */
	public static function fulltext_count( string $query ): int {
		$courses_table = \Learnomy\table( 'courses' );
		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT COUNT(*) FROM ' . static::table() . " AS l
				 INNER JOIN {$courses_table} AS c ON c.id = l.course_id AND c.status = 'published'
				 WHERE MATCH(l.title, l.content_plain) AGAINST(%s IN NATURAL LANGUAGE MODE)",
				$query
			)
		);
	}

	// -------------------------------------------------------------------------
	// Attachments — thin wrapper over Lesson_Attachment so callers can use
	// Lesson::get_attachments() / attach() / detach() without importing the
	// attachment model directly.
	// -------------------------------------------------------------------------

	/**
	 * List every attachment row for a lesson, ordered by sort_order.
	 *
	 * @param int $lesson_id Lesson row id.
	 * @return object[] Rows from `lrn_lesson_attachments` (id, media_id, filename, mime_type, size_bytes, sort_order).
	 */
	public static function get_attachments( int $lesson_id ): array {
		return Lesson_Attachment::get_for_lesson( $lesson_id );
	}

	/**
	 * Attach a WordPress media item to a lesson, returning the inserted
	 * attachment row id. A caller-supplied filename is persisted as-is so the
	 * list view can render a custom label without a media-library lookup.
	 *
	 * Attaching MOVES the media item's bytes into the protected store, so the
	 * handout stops being fetchable at its uploads URL. A media item that
	 * something else also links to (a featured image, a course thumbnail, an
	 * embed) cannot be moved without breaking that other thing, so it is
	 * refused with an explanation rather than silently left public.
	 *
	 * @param int         $lesson_id Target lesson.
	 * @param int         $media_id  WordPress attachment post id.
	 * @param string|null $filename  Optional override for the denormalised filename.
	 * @return int|\WP_Error New attachment row id (0 on failure), or WP_Error when
	 *                       the file could not be made private.
	 */
	public static function attach( int $lesson_id, int $media_id, ?string $filename = null ): int|\WP_Error {
		// Done here as well as inside Lesson_Attachment::create() so the reason
		// reaches the instructor. create()'s guard is the backstop that makes an
		// unprotected row impossible; this call is what makes the failure
		// explainable.
		$protected = \Learnomy\Services\Protected_Uploads::protect_media( $media_id );
		if ( is_wp_error( $protected ) ) {
			return $protected;
		}

		$row = [
			'lesson_id' => $lesson_id,
			'media_id'  => $media_id,
		];
		if ( null !== $filename && '' !== $filename ) {
			$row['filename'] = $filename;
		}
		$attachment_id = Lesson_Attachment::create( $row );

		if ( $attachment_id > 0 ) {
			do_action( 'learnomy_lesson_attachment_added', $lesson_id, $attachment_id, $media_id );
		}

		return $attachment_id;
	}

	/**
	 * Detach (delete) a single attachment row. The underlying WP media file is
	 * intentionally NOT removed — the library copy may still be referenced
	 * elsewhere.
	 */
	public static function detach( int $attachment_id ): bool {
		$row = Lesson_Attachment::find( $attachment_id );
		$ok  = Lesson_Attachment::detach( $attachment_id );
		if ( $ok && $row ) {
			do_action( 'learnomy_lesson_attachment_removed', (int) $row->lesson_id, $attachment_id );
		}
		return $ok;
	}

	/**
	 * How many lessons link to this media item outside the attachments table?
	 *
	 * Asked before relocating a file into the protected store: moving something
	 * a lesson body embeds would turn that embed into a broken image. Matched on
	 * the URL STEM (path without extension) so a generated size — `photo-300x200.jpg`
	 * from `photo.jpg` — is caught too; matching the exact URL would miss every
	 * resized embed, which is how most images are actually inserted.
	 *
	 * @param int    $media_id Attachment post id.
	 * @param string $url      The media item's current public URL.
	 */
	public static function media_reference_count( int $media_id, string $url ): int {
		if ( $media_id <= 0 && '' === $url ) {
			return 0;
		}

		$stem = (string) preg_replace( '/\.[a-zA-Z0-9]{1,8}$/', '', $url );
		if ( '' === $stem ) {
			return 0;
		}

		$like = '%' . static::db()->esc_like( $stem ) . '%';

		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE video_url LIKE %s OR content LIKE %s',
				$like,
				$like
			)
		);
	}

	/**
	 * Resolve the effective comments_enabled flag for a lesson, falling back
	 * to the parent course's `comments_enabled` setting when the lesson column
	 * is NULL (the sentinel for "inherit from course").
	 *
	 * @param int $lesson_id Lesson row id.
	 * @return bool True when comments should render on the lesson, false otherwise.
	 */
	public static function get_effective_comments_enabled( int $lesson_id ): bool {
		$lesson = static::find( $lesson_id );
		if ( ! $lesson ) {
			return true; // Sensible default for a missing row.
		}

		// Global master kill-switch. When the admin turns lesson comments off
		// site-wide (`learnomy_settings[enable_lesson_comments] = 0`), no
		// lesson- or course-level override can re-enable them. The lesson
		// player view-data already honors this for the UI; checking it here
		// makes the model the single source of truth so the REST write gates
		// stay consistent with what the page shows.
		$global = get_option( 'learnomy_settings', array() );
		if ( is_array( $global ) && array_key_exists( 'enable_lesson_comments', $global ) && empty( $global['enable_lesson_comments'] ) ) {
			return false;
		}

		// NULL column = inherit from course. Anything 0/1 is an explicit override.
		if ( null !== ( $lesson->comments_enabled ?? null ) ) {
			return (bool) (int) $lesson->comments_enabled;
		}

		$course_id = (int) ( $lesson->course_id ?? 0 );
		if ( $course_id <= 0 ) {
			return true;
		}

		$settings = Course::get_all_settings( $course_id );
		// Course-level key is also `comments_enabled`; default to ON when unset.
		if ( is_array( $settings ) && array_key_exists( 'comments_enabled', $settings ) ) {
			return (bool) $settings['comments_enabled'];
		}
		return true;
	}

	/** Lesson IDs for a course within a set of known IDs, ordered by section + sort. */
	public static function get_ids_for_course_in( int $course_id, array $ids ): array {
		if ( empty( $ids ) ) {
			return [];
		}

		$ids_csv = implode( ',', array_map( 'intval', $ids ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = static::db()->get_col(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT id FROM ' . static::table() . " WHERE course_id = %d AND id IN ({$ids_csv}) ORDER BY section_id ASC, sort_order ASC",
				$course_id
			)
		);

		return array_map( 'intval', $rows ?: [] );
	}

	/**
	 * Delete all lesson rows for a course.
	 *
	 * Called by CLI fixture teardown so lesson rows seeded during a test
	 * scenario are removed before their parent course row is deleted —
	 * preserving referential integrity without FK cascades.
	 */
	public static function delete_for_course( int $course_id ): bool {
		$deleted = false !== static::db()->delete( static::table(), [ 'course_id' => $course_id ] );
		static::invalidate_cache();
		return $deleted;
	}

	/**
	 * Delete a lesson AND every Free-owned row that depends on it, then the
	 * lesson row itself.
	 *
	 * Mirrors the model-owns-its-dependents cascade already used by
	 * {@see Enrollment::delete()}: take the dependents first so a mid-cascade
	 * failure never leaves the parent gone with children orphaned. Without this,
	 * a bare base-model row delete left orphaned `lrn_lesson_attachments`,
	 * `lrn_lesson_unlocks`, `lrn_progress`, `lrn_tag_map`, and attached
	 * `lrn_quizzes` rows (cascade-orphan bug). Pro-owned tables (notes/bookmarks,
	 * content-drip) already clean up via the `learnomy_lesson_deleted` action
	 * fired by the REST controller, so they stay out of this method.
	 *
	 * Topics (child lessons where `parent_id` = this lesson) are themselves
	 * lessons carrying the same dependent rows, so they recurse through this
	 * same cascade rather than being left behind.
	 *
	 * @param int $id Lesson row id.
	 * @return bool True when the lesson row was removed.
	 */
	public static function delete( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		// Topics first — each is a lesson with its own dependent rows. Every
		// status: the cascade must never leave a draft topic orphaned.
		foreach ( static::get_topics( $id, null ) as $topic ) {
			static::delete( (int) $topic->id );
		}

		// Quizzes attached to this lesson go through the full quiz cascade
		// (responses -> attempts -> slots -> quiz) so their children don't
		// orphan either. Quiz::get_for_lesson() only returns the first
		// published quiz, so query the lesson_id directly for every attached
		// quiz regardless of status.
		$quiz_service = new \Learnomy\Services\Quiz_Service();
		foreach ( Quiz::where( [ 'lesson_id' => $id ] ) as $quiz ) {
			$quiz_service->delete_quiz( (int) $quiz->id );
		}

		// Free-owned dependent tables.
		Lesson_Attachment::delete_for_lesson( $id );
		Lesson_Unlock::delete_for_lesson( $id );
		Progress::delete_for_lesson( $id );
		// Empty tag set clears the lesson's tag_map rows AND recomputes each
		// affected tag's denormalised usage_count — the canonical chokepoint.
		Tag::sync_object_tags( $id, 'lesson', [] );

		return parent::delete( $id );
	}
}


/**
 * Enqueue the lesson-form stylesheet on the lesson editor admin page and
 * ensure the WP media library JS is loaded so the attachments uploader can
 * open `wp.media({multiple:true})`. Hooked here so the owned CSS ships
 * without editing class-admin.php.
 */
add_action(
	'admin_enqueue_scripts',
	static function ( $hook ): void {
		// Match by hook suffix, not a literal prefix: the screen-hook prefix
		// varies (`admin_page_` vs `<menu>_page_`) and a white-label menu rename
		// changes it again, so a literal comparison silently breaks and the
		// completion-rules / attachments styles never load.
		if ( ! str_ends_with( (string) $hook, '_page_learnomy-lesson-editor' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style(
			'learnomy-lesson-form',
			LEARNOMY_URL . 'assets/css/admin/lesson-form.css',
			[ 'learnomy-admin' ],
			defined( 'LEARNOMY_VERSION' ) ? LEARNOMY_VERSION : '1.0.0'
		);
	},
	20
);
