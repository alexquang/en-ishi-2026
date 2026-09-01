<?php
/**
 * Migration import orchestrator.
 *
 * Reads a source (via a Migration_Source module) and POPULATES Learnomy through
 * its own services/models — never direct DB. Honours the run's selection/mapping
 * spec, is idempotent via the Id_Map, and runs in dependency order.
 *
 * In-place model: Learnomy is installed ON the source site, so wp_users is shared
 * — source author/user IDs map directly to Learnomy IDs (no email matching).
 *
 * @package Learnomy
 */

namespace Learnomy\Migration;

use Learnomy\Models\Answer;
use Learnomy\Models\Category;
use Learnomy\Models\Course;
use Learnomy\Models\CourseInstructor;
use Learnomy\Models\Lesson;
use Learnomy\Models\Question;
use Learnomy\Models\Quiz;
use Learnomy\Models\QuizSlot;
use Learnomy\Models\Section;
use Learnomy\Models\Tag;
use Learnomy\Services\Course_Service;

use function Learnomy\now;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a migration source and populates Learnomy via its own services/models.
 */
class Import_Service {

	/** Apply order — parents before children; people (enrollment/progress) last. */
	private const ORDER = array( 'category', 'course', 'section', 'lesson', 'sub_lesson', 'quiz', 'question', 'enrollment', 'progress' );

	/** Rows processed per page (also the async batch size). */
	public const PAGE = 50;

	/**
	 * The source module being imported.
	 *
	 * @var Migration_Source
	 */
	private Migration_Source $source;

	/**
	 * The run's selection/mapping spec.
	 *
	 * @var array
	 */
	private array $spec;

	/**
	 * The id-map powering idempotent re-runs + parent resolution.
	 *
	 * @var Id_Map
	 */
	private Id_Map $map;

	/**
	 * Per-run counters + warnings surfaced to the UI/CLI.
	 *
	 * @var array
	 */
	private array $report = array(
		'created'  => array(),
		'updated'  => array(),
		'skipped'  => array(),
		'warnings' => array(),
	);

	/**
	 * Constructor.
	 *
	 * @param Migration_Source $source The source module to import from.
	 * @param array            $spec   The run's selection/mapping spec.
	 */
	public function __construct( Migration_Source $source, array $spec ) {
		$this->source = $source;
		$this->spec   = $spec;
		$this->map    = new Id_Map( (string) ( $spec['run'] ?? 'run' ) );

		// Mute notifications for the whole migration request (sync run + async
		// step both build an Import_Service). Importing historical enrollments /
		// progress for thousands of users must NOT email each of them a
		// "Welcome / You are enrolled" notice. Preview + undo construct this too
		// but never send notifications, so enabling it here is harmless for them.
		if ( false === has_filter( 'learnomy_suppress_notifications', '__return_true' ) ) {
			add_filter( 'learnomy_suppress_notifications', '__return_true' );
		}
	}

	/** Selected entities, filtered to what the source supports + apply order. */
	private function entities(): array {
		$selected  = (array) ( $this->spec['entities'] ?? $this->source->supported_entities() );
		$supported = $this->source->supported_entities();
		return array_values( array_intersect( self::ORDER, $selected, $supported ) );
	}

	// ── Dry run ──────────────────────────────────────────────────────────────

	/**
	 * Preview: counts per entity + warnings (e.g. question types with no clean
	 * Learnomy target, given the run's fallback map). No writes.
	 */
	public function preview(): array {
		$out = array(
			'source'       => $this->source->slug(),
			'entities'     => array(),
			'warnings'     => array(),
			'not_migrated' => $this->not_migrated(),
		);

		foreach ( $this->entities() as $entity ) {
			$out['entities'][ $entity ] = $this->source->count( $entity );
		}

		// Surface question-type fallbacks that will downgrade/skip.
		if ( in_array( 'question', $this->entities(), true ) ) {
			$offset = 0;
			$seen   = array();
			while ( true ) {
				$rows = $this->source->read( 'question', self::PAGE, $offset );
				if ( empty( $rows ) ) {
					break;
				}
				foreach ( $rows as $row ) {
					$payload = $this->source->map( 'question', $row, $this->spec );
					if ( null === $payload ) {
						continue;
					}
					$orig = (string) ( $payload['orig_type'] ?? '' );
					if ( $orig && ! isset( $seen[ $orig ] ) ) {
						$seen[ $orig ] = true;
						if ( ! empty( $payload['skip'] ) ) {
							$out['warnings'][] = sprintf( 'Question type "%s" has no Learnomy equivalent and will be SKIPPED.', $orig );
						} elseif ( ! empty( $payload['note'] ) ) {
							$out['warnings'][] = (string) $payload['note'];
						}
					}
				}
				$offset += self::PAGE;
			}
		}

		return $out;
	}

	// ── Apply ────────────────────────────────────────────────────────────────

	/** Run the import (synchronous, paginated). Returns the report. */
	public function run(): array {
		foreach ( $this->entities() as $entity ) {
			$offset = 0;
			while ( $this->process_page( $entity, $offset ) > 0 ) {
				$offset += self::PAGE;
			}
		}
		$this->finalize_prerequisites();
		return $this->report;
	}

	/**
	 * Resolve + write course prerequisites once every course is mapped.
	 *
	 * Prerequisites are forward references (course A can require course B that
	 * imports later in the same course phase), so they cannot be resolved inline
	 * during import_course. This pass runs after all courses exist: for each
	 * imported course it re-reads the source prerequisite ext-ids, resolves them
	 * through the now-complete id-map, and writes the JSON id list Learnomy's
	 * Course_Service::check_prerequisites() expects. Idempotent — safe on re-run
	 * and on the async finish. Only courses that actually declare prerequisites
	 * are touched.
	 *
	 * @return void
	 */
	public function finalize_prerequisites(): void {
		if ( ! in_array( 'course', $this->entities(), true ) ) {
			return;
		}
		$slug    = $this->source->slug();
		$courses = $this->map->mappings_for_entity( $slug, 'course' );

		foreach ( $courses as $ext_id => $learnomy_id ) {
			$payload = $this->source->map( 'course', (int) $ext_id, $this->spec );
			$ext_pre = is_array( $payload ) ? (array) ( $payload['prerequisite_ext_ids'] ?? array() ) : array();
			if ( empty( $ext_pre ) ) {
				continue;
			}

			$resolved = array();
			foreach ( $ext_pre as $pre_ext ) {
				$pid = (int) ( $this->map->resolve( $slug, 'course', $pre_ext ) ?? 0 );
				// A prerequisite the operator chose not to import (deselected, or a
				// different status) simply won't resolve — skip it, don't gate on a
				// course that isn't in Learnomy.
				if ( $pid > 0 && $pid !== (int) $learnomy_id ) {
					$resolved[ $pid ] = $pid;
				}
			}
			if ( ! empty( $resolved ) ) {
				Course::update( (int) $learnomy_id, array( 'prerequisites' => (string) wp_json_encode( array_values( $resolved ) ) ) );
			}
		}
	}

	/**
	 * Process ONE page of ONE entity (the unit of async batching). Reads up to
	 * PAGE source rows at $offset, maps + imports each, and accumulates into the
	 * in-memory report. Returns the number of SOURCE rows read (0 = entity done)
	 * so a caller can advance the cursor even when every row was filtered out.
	 *
	 * @param string $entity Entity key.
	 * @param int    $offset Page offset.
	 * @return int Source rows read this page.
	 */
	public function process_page( string $entity, int $offset ): int {
		$rows = $this->source->read( $entity, self::PAGE, $offset );
		foreach ( $rows as $row ) {
			$payload = $this->source->map( $entity, $row, $this->spec );
			if ( null !== $payload ) {
				$this->import_one( $entity, $payload );
			}
		}
		return count( $rows );
	}

	/** The selected entities in apply order (for the async stepper / preview). */
	public function entity_list(): array {
		return $this->entities();
	}

	/**
	 * What this importer deliberately does NOT migrate — stated plainly so the
	 * site owner is never surprised. These are not half-built toggles; they are
	 * out of scope by design (no reliable full flow exists), so we say so.
	 * Filterable per source for any source-specific note.
	 *
	 * @return string[]
	 */
	public function not_migrated(): array {
		return self::exclusions( $this->source->slug() );
	}

	/**
	 * The exclusion notes, resolvable WITHOUT an Import_Service instance.
	 *
	 * Static because the same list has to appear in two places and used to appear
	 * in one. `preview()` showed it, so an owner saw it on the dry-run step and
	 * never again — the completion screen listed counters and warnings only, so
	 * anyone who clicked through the preview finished the import believing
	 * everything came across (Basecamp 10143404772). `Migration_Run::summary()`
	 * has no Import_Service, hence a static entry point rather than a copy of the
	 * strings.
	 *
	 * @param string $source_slug Source being imported from.
	 * @return string[]
	 */
	public static function exclusions( string $source_slug ): array {
		$items = array(
			// The reason matters, because "not migrated" invites "why not / can you
			// add it". A source certificate is a design in THAT plugin's template
			// system — its own background image, its own shortcodes for the
			// recipient and course — none of which mean anything to Learnomy's
			// renderer. Carrying the rows over would produce credentials that
			// reference images and tags we cannot resolve, which is worse than not
			// having them. Migration covers learning material; the certificate a
			// student earned is re-issued from a Learnomy template instead.
			__( 'Certificates are not migrated. They are built from the other plugin\'s own template, background image and shortcodes, so they cannot be carried over as they are. Pick a Learnomy certificate template per course after importing, and completions will issue certificates from that.', 'learnomy' ),
			__( 'Quiz attempt scores are not reconstructed. Learners who passed are marked complete; per-question scores are not carried over.', 'learnomy' ),
			__( 'Payments, pricing, coupons, and orders are never touched. Imported courses are free; money stays in your existing system.', 'learnomy' ),
		);

		/**
		 * Filter the "not migrated" list, per source. Shown on BOTH the dry-run
		 * and the completion screen.
		 *
		 * @param string[] $items  Plain-language exclusion notes.
		 * @param string   $source The source slug being previewed or imported.
		 */
		return (array) apply_filters( 'learnomy_migration_not_migrated', $items, $source_slug );
	}

	/** The accumulated per-run report (created/updated/skipped/warnings). */
	public function report(): array {
		return $this->report;
	}

	// ── Spec defaults (shared by the CLI + the wizard REST controller) ────────

	/**
	 * Default per-source-type fallbacks for question types with no exact
	 * Learnomy target (resolved 2026-06-15). Filterable so a site can change the
	 * portfolio-wide default; the run's --map / wizard overrides still win.
	 *
	 * @return array<string,string>
	 */
	public static function default_question_type_fallbacks(): array {
		return apply_filters(
			'learnomy_migration_question_type_fallbacks',
			array(
				'image_matching'    => 'matching',
				'image_answering'   => 'short_answer',
				'h5p'               => 'skip',
				'assessment_answer' => 'skip',
				'scale'             => 'skip',
				'picture_choice'    => 'mc_single',
				'picture_reorder'   => 'ordering',
				'upload'            => 'essay',
				'code'              => 'essay',
			)
		);
	}

	/**
	 * Build a run spec with a fresh run id + defaults, merging caller overrides.
	 * The single source of truth for both `wp learnomy migrate` and the wizard.
	 *
	 * @param string $source    Source slug.
	 * @param array  $overrides entities / taxonomies / question_type_fallbacks / collision …
	 * @return array
	 */
	public static function build_spec( string $source, array $overrides = array() ): array {
		$spec = array(
			'source'                  => $source,
			'run'                     => gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false, false ),
			'question_type_fallbacks' => self::default_question_type_fallbacks(),
			'attempt_scores'          => 'completion_only',
			'collision'               => 'skip',
		);
		if ( ! empty( $overrides['question_type_fallbacks'] ) ) {
			// Caller fallbacks override per-type, keeping the rest of the defaults.
			$overrides['question_type_fallbacks'] = array_merge(
				$spec['question_type_fallbacks'],
				(array) $overrides['question_type_fallbacks']
			);
		}
		return array_merge( $spec, $overrides );
	}

	// ── Async (Action Scheduler) ──────────────────────────────────────────

	/** The async step hook (registered in Cron). */
	public const STEP_HOOK = 'learnomy_migration_step';

	/**
	 * Begin an import as a resumable, Action-Scheduler-batched run. Creates the
	 * run record (totals + cursor) and enqueues the first step. When Action
	 * Scheduler is unavailable the whole import runs synchronously here so the
	 * caller still gets a completed run back.
	 *
	 * @param Migration_Source $source The source to import from.
	 * @param array            $spec   The run's selection/mapping spec (carries 'run').
	 * @return Migration_Run
	 */
	public static function start_async( Migration_Source $source, array $spec ): Migration_Run {
		$svc      = new self( $source, $spec );
		$entities = $svc->entity_list();
		$totals   = array();
		foreach ( $entities as $entity ) {
			$totals[ $entity ] = $source->count( $entity );
		}
		$run = Migration_Run::create( $source->slug(), $spec, $entities, $totals );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::STEP_HOOK, array( $run->id ), 'learnomy' );
		} else {
			self::run_to_completion( $run );
		}
		return $run;
	}

	/**
	 * Process one page for a run, then re-enqueue itself until complete. This is
	 * the `learnomy_migration_step` Action Scheduler handler.
	 *
	 * @param string $run_id The run id to advance.
	 * @return void
	 */
	public static function step( string $run_id ): void {
		$run = Migration_Run::load( $run_id );
		if ( ! $run || 'running' !== $run->status ) {
			return;
		}
		$source = Source_Registry::get( $run->source );
		if ( ! $source ) {
			$run->fail( sprintf( 'Source "%s" is no longer available.', $run->source ) );
			$run->save();
			return;
		}

		try {
			$entity = $run->current_entity();
			if ( '' !== $entity ) {
				$svc  = new self( $source, $run->spec );
				$read = $svc->process_page( $entity, (int) $run->cursor['offset'] );
				$run->merge( $svc->report() );
				$run->advance( $read );
			} else {
				// All entities done — resolve forward-referencing course
				// prerequisites now that every course is mapped, then finish.
				( new self( $source, $run->spec ) )->finalize_prerequisites();
				$run->finish();
			}
			$run->save();
		} catch ( \Throwable $e ) {
			$run->fail( $e->getMessage() );
			$run->save();
			return;
		}

		if ( ! $run->is_complete() && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::STEP_HOOK, array( $run_id ), 'learnomy' );
		}
	}

	/**
	 * Undo a run: delete exactly what it created (children + tag-map links
	 * first), purge the id-map rows, and drop the run record. Shared by the CLI
	 * and the wizard so the cascade logic lives in one place.
	 *
	 * @param string $run The run id to undo.
	 * @return array{deleted:int,map_rows:int,found:bool} Outcome counts.
	 */
	public static function undo_run( string $run ): array {
		$entries = Id_Map::entries_for_run( $run );
		if ( empty( $entries ) ) {
			$record = Migration_Run::load( $run );
			if ( $record ) {
				$record->delete();
			}
			return array(
				'deleted'  => 0,
				'map_rows' => 0,
				'found'    => null !== $record,
			);
		}

		$model_map    = array(
			'category'   => \Learnomy\Models\Category::class,
			'course'     => \Learnomy\Models\Course::class,
			'section'    => \Learnomy\Models\Section::class,
			'lesson'     => \Learnomy\Models\Lesson::class,
			'sub_lesson' => \Learnomy\Models\Lesson::class,
			'quiz'       => \Learnomy\Models\Quiz::class,
			'question'   => \Learnomy\Models\Question::class,
			'enrollment' => \Learnomy\Models\Enrollment::class,
			'progress'   => \Learnomy\Models\Progress::class,
		);
		$tag_type_map = array(
			'course'     => 'course',
			'lesson'     => 'lesson',
			'sub_lesson' => 'lesson',
			'quiz'       => 'quiz',
			'question'   => 'question',
		);

		// entries_for_run() returns id DESC (children created last -> deleted
		// first). Each delete also tears down the child rows the base
		// Model::delete() leaves behind (no quiz/question cascade at the model
		// layer) and the object's tag-map links. Shared tag rows are left intact
		// (find-or-create; may pre-date the import) with usage dropped to zero.
		$deleted = 0;
		foreach ( $entries as $row ) {
			$type  = (string) $row->object_type;
			$id    = (int) $row->object_id;
			$model = $model_map[ $type ] ?? null;
			if ( ! $model ) {
				continue;
			}
			if ( isset( $tag_type_map[ $type ] ) ) {
				\Learnomy\Models\Tag::sync_object_tags( $id, $tag_type_map[ $type ], array() );
			}
			if ( 'question' === $type ) {
				\Learnomy\Models\Answer::delete_for_question( $id );
				\Learnomy\Models\QuizSlot::delete_for_question( $id );
			} elseif ( 'quiz' === $type ) {
				\Learnomy\Models\QuizSlot::delete_for_quiz( $id );
			}
			$model::delete( $id );
			++$deleted;
		}

		$map_rows = Id_Map::delete_run( $run );

		$record = Migration_Run::load( $run );
		if ( $record ) {
			$record->delete();
		}

		return array(
			'deleted'  => $deleted,
			'map_rows' => $map_rows,
			'found'    => true,
		);
	}

	/**
	 * Drive a run to completion synchronously (the no-Action-Scheduler path).
	 *
	 * @param Migration_Run $run The run to complete.
	 * @return void
	 */
	private static function run_to_completion( Migration_Run $run ): void {
		$source = Source_Registry::get( $run->source );
		if ( ! $source ) {
			$run->fail( sprintf( 'Source "%s" is no longer available.', $run->source ) );
			$run->save();
			return;
		}
		while ( ! $run->is_complete() ) {
			$svc  = new self( $source, $run->spec );
			$read = $svc->process_page( $run->current_entity(), (int) $run->cursor['offset'] );
			$run->merge( $svc->report() );
			$run->advance( $read );
		}
		$run->save();
	}

	/**
	 * Increment a report counter for an entity.
	 *
	 * @param string $bucket Report bucket (created/updated/skipped).
	 * @param string $entity Entity key.
	 * @return void
	 */
	private function bump( string $bucket, string $entity ): void {
		$this->report[ $bucket ][ $entity ] = ( $this->report[ $bucket ][ $entity ] ?? 0 ) + 1;
	}

	/**
	 * Import one normalized payload (idempotent via the id-map).
	 *
	 * @param string $entity Entity key.
	 * @param array  $p      Normalized payload.
	 * @return void
	 */
	private function import_one( string $entity, array $p ): void {
		$ext = (string) ( $p['ext_id'] ?? '' );

		// Idempotent: already imported this source row? skip (re-run safe).
		if ( '' !== $ext && null !== $this->map->resolve( $this->source->slug(), $entity, $ext ) ) {
			$this->bump( 'skipped', $entity );
			return;
		}

		$id = 0;
		switch ( $entity ) {
			case 'category':
				$id = $this->import_category( $p );
				break;
			case 'course':
				$id = $this->import_course( $p );
				break;
			case 'section':
				$id = $this->import_section( $p );
				break;
			case 'lesson':
			case 'sub_lesson':
				$id = $this->import_lesson( $p, 'sub_lesson' === $entity );
				break;
			case 'quiz':
				$id = $this->import_quiz( $p );
				break;
			case 'question':
				$id = $this->import_question( $p );
				break;
			case 'enrollment':
				$id = $this->import_enrollment( $p );
				break;
			case 'progress':
				$id = $this->import_progress( $p );
				break;
		}

		if ( $id > 0 ) {
			if ( '' !== $ext ) {
				$this->map->remember( $this->source->slug(), $entity, $ext, $id );
			}
			$this->bump( 'created', $entity );
		} else {
			$this->bump( 'skipped', $entity );
		}
	}

	// ── Per-entity writers (Learnomy services/models ONLY) ────────────────────

	/**
	 * Create (or reuse) a Learnomy category from a payload.
	 *
	 * @param array $p Normalized category payload.
	 * @return int
	 */
	private function import_category( array $p ): int {
		$slug = sanitize_title( (string) ( $p['slug'] ?? $p['name'] ?? '' ) );
		if ( '' === $slug ) {
			return 0;
		}
		$existing = Category::find_by_slug( $slug );
		if ( $existing ) {
			// Reuse an existing Learnomy category of the same slug.
			return (int) $existing->id;
		}
		$parent_id = 0;
		if ( ! empty( $p['parent_ext_id'] ) ) {
			$parent_id = (int) ( $this->map->resolve( $this->source->slug(), 'category', $p['parent_ext_id'] ) ?? 0 );
		}
		return (int) Category::insert(
			array(
				'name'       => (string) ( $p['name'] ?? $slug ),
				'slug'       => $slug,
				'parent_id'  => $parent_id,
				'type'       => 'course',
				'created_at' => now(),
			)
		);
	}

	/**
	 * Create a Learnomy course from a payload.
	 *
	 * @param array $p Normalized course payload.
	 * @return int
	 */
	private function import_course( array $p ): int {
		// Render any Gutenberg blocks (embeds, etc.) to final HTML — Learnomy's
		// display pipeline never runs the_content, so unrendered blocks would show
		// as bare URLs / empty.
		$body = \Learnomy\render_wp_blocks( (string) ( $p['content'] ?? '' ) );
		$body = $this->append_text_blocks( $body, (array) ( $p['text_blocks'] ?? array() ) );

		// Field mapping for DISPLAY: the single-course page renders `description`
		// (the rich "About" body, via render_editorjs_content) and `short_description`
		// (the plain tagline); `content` is not surfaced by any course template. The
		// source post_content is the rich body and post_excerpt is the short summary
		// — so the body must land in `description`, the excerpt in `short_description`.
		// (The old mapping put the body in `content` and the excerpt in `description`,
		// so the whole course body — headings, images, video — never displayed.)
		$excerpt = (string) ( $p['description'] ?? '' );
		$about   = '' !== trim( $body ) ? $body : $excerpt;

		$data = array(
			'title'             => (string) ( $p['title'] ?? '' ),
			'short_description' => $excerpt,
			// Importer body is rendered HTML; canonicalise through the one shared
			// converter under the cutover so it converges with editor saves (INV-9).
			// `description_plain` follows from Course::search_projections() on the
			// write, so the imported course is findable by the G16 catalog
			// FULLTEXT search without this path deriving it itself.
			'description'       => \Learnomy\Content\Content_HTML::storage_enabled() ? \Learnomy\Content\Content_HTML::for_storage( $about ) : $about,
			'content'           => $body,
			'status'            => $this->norm_status( $p['status'] ?? 'draft' ),
			'access_type'       => 'free',   // money is out of scope.
			'pricing_model'     => 'free',
			'difficulty'        => (string) ( $p['difficulty'] ?? '' ),
			'duration_seconds'  => (int) ( $p['duration_seconds'] ?? 0 ),
			'thumbnail_url'     => (string) ( $p['thumbnail_url'] ?? '' ),
			'video_url'         => (string) ( $p['video_url'] ?? '' ),
		);
		if ( ! empty( $p['slug'] ) ) {
			$data['slug'] = sanitize_title( (string) $p['slug'] );
		}

		// Primary category -> category_id; the rest become course tags.
		$cat_ext = $p['primary_category_ext_id'] ?? ( ( $p['category_ext_ids'][0] ?? null ) );
		if ( $cat_ext ) {
			$data['category_id'] = (int) ( $this->map->resolve( $this->source->slug(), 'category', $cat_ext ) ?? 0 );
		}

		$author_id = (int) ( $p['author_id'] ?? get_current_user_id() );
		$course_id = (int) ( new Course_Service() )->create( $data, $author_id );
		if ( ! $course_id ) {
			return 0;
		}

		if ( ! empty( $p['settings'] ) && is_array( $p['settings'] ) ) {
			Course::update_settings( $course_id, $p['settings'] );
		}

		// Co-instructors. Course_Service::create() already added the primary
		// (author) at 100%; add the rest as non-primary. Money is out of scope for
		// migration, so they carry a 0% commission share — the relationship (who
		// teaches the course) is what we preserve. Shared wp_users (in-place model)
		// means source user IDs map directly; skip any that aren't real users.
		foreach ( (array) ( $p['co_instructor_ids'] ?? array() ) as $co_id ) {
			$co_id = (int) $co_id;
			if ( $co_id > 0 && $co_id !== $author_id && get_userdata( $co_id ) ) {
				CourseInstructor::add_instructor( $course_id, $co_id, 0.00, false );
			}
		}

		// Extra categories (beyond primary) + explicit tags -> course tags.
		$tag_names = (array) ( $p['tag_names'] ?? array() );
		foreach ( (array) ( $p['extra_category_names'] ?? array() ) as $name ) {
			$tag_names[] = $name;
		}
		$this->assign_tags( $course_id, 'course', $tag_names );

		return $course_id;
	}

	/**
	 * Create a Learnomy section from a payload.
	 *
	 * @param array $p Normalized section payload.
	 * @return int
	 */
	private function import_section( array $p ): int {
		$course_id = (int) ( $this->map->resolve( $this->source->slug(), 'course', $p['course_ext_id'] ?? '' ) ?? 0 );
		if ( ! $course_id ) {
			return 0;
		}
		return (int) Section::insert(
			array(
				'course_id'   => $course_id,
				'title'       => (string) ( $p['title'] ?? '' ),
				'description' => (string) ( $p['description'] ?? '' ),
				'sort_order'  => (int) ( $p['sort_order'] ?? 0 ),
				'created_at'  => now(),
			)
		);
	}

	/**
	 * Create a Learnomy lesson (or sub-lesson) from a payload.
	 *
	 * @param array $p      Normalized lesson payload.
	 * @param bool  $is_sub Whether this is a sub-lesson (parent is another lesson).
	 * @return int
	 */
	private function import_lesson( array $p, bool $is_sub ): int {
		$course_id = (int) ( $this->map->resolve( $this->source->slug(), 'course', $p['course_ext_id'] ?? '' ) ?? 0 );
		if ( ! $course_id ) {
			return 0;
		}
		// Source HTML → canonical semantic HTML under the cutover (converges with
		// editor saves, INV-9); legacy JSON-document wrap only when kill-switched.
		$lesson_html = \Learnomy\render_wp_blocks( (string) ( $p['content_html'] ?? '' ) );
		$data        = array(
			'course_id'        => $course_id,
			'author_id'        => (int) ( $p['author_id'] ?? get_current_user_id() ),
			'title'            => (string) ( $p['title'] ?? '' ),
			'content'          => \Learnomy\Content\Content_HTML::storage_enabled() ? \Learnomy\Content\Content_HTML::for_storage( $lesson_html ) : \Learnomy\html_to_editorjs_document( $lesson_html ),
			// The source DTO may carry its own `content_plain`, but it is derived
			// from the FOREIGN post body, not from what we are about to store.
			// Ignore it: Lesson::search_projections() derives the projection from
			// the canonical content on the write below, so an imported lesson is
			// indexed identically to one saved in our own editor.
			'content_type'     => (string) ( $p['content_type'] ?? 'text' ),
			'video_url'        => (string) ( $p['video_url'] ?? '' ),
			'is_preview'       => ! empty( $p['is_preview'] ) ? 1 : 0,
			'duration_seconds' => (int) ( $p['duration_seconds'] ?? 0 ),
			'min_time_seconds' => (int) ( $p['min_time_seconds'] ?? 0 ),
			'sort_order'       => (int) ( $p['sort_order'] ?? 0 ),
			'status'           => $this->norm_status( $p['status'] ?? 'published' ),
		);
		if ( ! empty( $p['section_ext_id'] ) ) {
			$data['section_id'] = (int) ( $this->map->resolve( $this->source->slug(), 'section', $p['section_ext_id'] ) ?? 0 );
		}
		if ( $is_sub && ! empty( $p['parent_ext_id'] ) ) {
			// A sub-lesson's parent is itself a lesson imported earlier.
			$data['parent_id'] = (int) ( $this->map->resolve( $this->source->slug(), 'lesson', $p['parent_ext_id'] ) ?? 0 );
		}

		$lesson_id = (int) Lesson::create( $data );
		if ( $lesson_id ) {
			$this->assign_tags( $lesson_id, 'lesson', (array) ( $p['tag_names'] ?? array() ) );
		}
		return $lesson_id;
	}

	/**
	 * Create a Learnomy quiz from a payload.
	 *
	 * @param array $p Normalized quiz payload.
	 * @return int
	 */
	private function import_quiz( array $p ): int {
		$course_id = (int) ( $this->map->resolve( $this->source->slug(), 'course', $p['course_ext_id'] ?? '' ) ?? 0 );
		$data      = array(
			'course_id'          => $course_id,
			'title'              => (string) ( $p['title'] ?? '' ),
			'mode'               => (string) ( $p['mode'] ?? 'graded' ),
			'passing_score'      => (int) ( $p['passing_score'] ?? 70 ),
			'time_limit_seconds' => (int) ( $p['time_limit_seconds'] ?? 0 ),
			'attempt_limit'      => (int) ( $p['attempt_limit'] ?? 0 ),
			'shuffle_questions'  => ! empty( $p['shuffle_questions'] ) ? 1 : 0,
			'author_id'          => (int) ( $p['author_id'] ?? get_current_user_id() ),
			'status'             => $this->norm_status( $p['status'] ?? 'published' ),
		);
		if ( ! empty( $p['lesson_ext_id'] ) ) {
			$data['lesson_id'] = (int) ( $this->map->resolve( $this->source->slug(), 'lesson', $p['lesson_ext_id'] ) ?? 0 );
		}
		// Quiz::create (not insert) derives a per-course-unique slug from the
		// title. lrn_quizzes has UNIQUE (course_id, slug); inserting without a
		// slug makes every quiz after the first per course collide on
		// (course_id, '') and silently drop. create() also keeps slug routing
		// in lockstep with the front end (matches the Lesson::create path above).
		$quiz_id = (int) Quiz::create( $data );
		if ( $quiz_id ) {
			$this->assign_tags( $quiz_id, 'quiz', (array) ( $p['tag_names'] ?? array() ) );
		}
		return $quiz_id;
	}

	/**
	 * Create a Learnomy question (plus answers + quiz slot) from a payload.
	 *
	 * @param array $p Normalized question payload.
	 * @return int
	 */
	private function import_question( array $p ): int {
		if ( ! empty( $p['skip'] ) ) {
			$this->report['warnings'][] = sprintf( 'Skipped question "%s" (source type %s has no Learnomy target).', $p['title'] ?? '', $p['orig_type'] ?? '?' );
			return 0;
		}

		$question_id = (int) Question::insert(
			array(
				'type'        => (string) ( $p['type'] ?? 'mc_single' ),
				'title'       => (string) ( $p['title'] ?? '' ),
				'content'     => (string) ( $p['content'] ?? '' ),
				'explanation' => (string) ( $p['explanation'] ?? '' ),
				'hint'        => (string) ( $p['hint'] ?? '' ),
				'difficulty'  => (int) ( $p['difficulty'] ?? 3 ),
				'version'     => 1,
				'author_id'   => (int) ( $p['author_id'] ?? get_current_user_id() ),
				'created_at'  => now(),
				'updated_at'  => now(),
			)
		);
		if ( ! $question_id ) {
			return 0;
		}

		$order = 1;
		foreach ( (array) ( $p['answers'] ?? array() ) as $a ) {
			$row = array(
				'question_id' => $question_id,
				'content'     => (string) ( $a['content'] ?? '' ),
				'is_correct'  => ! empty( $a['is_correct'] ) ? 1 : 0,
				'fraction'    => isset( $a['fraction'] ) ? (float) $a['fraction'] : ( ! empty( $a['is_correct'] ) ? 1.0 : 0.0 ),
				'sort_order'  => isset( $a['sort_order'] ) ? (int) $a['sort_order'] : $order++,
			);
			if ( isset( $a['group_id'] ) && '' !== (string) $a['group_id'] ) {
				$row['group_id'] = (string) $a['group_id'];
			}
			Answer::insert( $row );
		}

		// Attach to its quiz via a slot.
		$quiz_id = (int) ( $this->map->resolve( $this->source->slug(), 'quiz', $p['quiz_ext_id'] ?? '' ) ?? 0 );
		if ( $quiz_id ) {
			QuizSlot::insert(
				array(
					'quiz_id'     => $quiz_id,
					'question_id' => $question_id,
					'sort_order'  => (int) ( $p['sort_order'] ?? 0 ),
					'marks'       => isset( $p['marks'] ) ? (float) $p['marks'] : 1.0,
				)
			);
		}

		return $question_id;
	}

	/**
	 * Grant a user access to a course (the "people" tier). Access-only: the
	 * course is free (money out of scope), the enrollment source is 'manual'
	 * (an admin-style historical grant). Completed source enrollments are also
	 * marked complete so the learner's standing carries over.
	 *
	 * @param array $p Normalized enrollment payload (user_id, course_ext_id, completed_at?).
	 * @return int Enrollment id, or 0 to skip.
	 */
	private function import_enrollment( array $p ): int {
		$user_id   = (int) ( $p['user_id'] ?? 0 );
		$course_id = (int) ( $this->map->resolve( $this->source->slug(), 'course', $p['course_ext_id'] ?? '' ) ?? 0 );
		if ( $user_id <= 0 || $course_id <= 0 || ! get_userdata( $user_id ) ) {
			return 0;
		}

		// Reuse an existing enrollment (re-run / already a member) — keeps the
		// import idempotent even when the id-map was cleared.
		$existing = \Learnomy\Models\Enrollment::find_for_user_course( $user_id, $course_id );
		if ( $existing ) {
			$enrollment_id = (int) $existing->id;
		} else {
			// Source 'free' matches the imported courses' access_type (money is out
			// of scope, so every migrated course is free). enroll() returns a
			// WP_Error when the access-type guard rejects the source, or 0 on a
			// prerequisite miss — treat both as a skip, never cast to a fake id.
			$result        = ( new \Learnomy\Services\Enrollment_Service() )->enroll( $user_id, $course_id, 'free' );
			$enrollment_id = is_wp_error( $result ) ? 0 : (int) $result;
		}

		if ( $enrollment_id <= 0 ) {
			return 0;
		}

		// Backfill the historical dates. enroll() / mark_completed() stamp now(),
		// but a migration must preserve WHEN the student actually enrolled and
		// completed — otherwise every imported learner looks like they joined on
		// migration day. Sources hand us either a Unix timestamp (LearnDash) or a
		// MySQL datetime (LifterLMS/Tutor); normalize_datetime() handles both.
		$backfill    = array();
		$enrolled_at = $this->normalize_datetime( $p['enrolled_at'] ?? '' );
		if ( '' !== $enrolled_at ) {
			$backfill['enrolled_at'] = $enrolled_at;
		}

		if ( ! empty( $p['completed_at'] ) ) {
			( new \Learnomy\Services\Enrollment_Service() )->mark_completed( $enrollment_id );
			$completed_at = $this->normalize_datetime( $p['completed_at'] );
			if ( '' !== $completed_at ) {
				$backfill['completed_at'] = $completed_at;
			}
			// A completed course is 100% by definition. mark_completed() flips the
			// status to `completed`, after which the per-lesson progress import
			// (Progress_Service::mark_lesson_complete -> Enrollment::find_active)
			// can no longer reach this enrollment to recompute the bar — find_active
			// only matches status='active'. So set the percentage here, otherwise a
			// completed learner imports stuck at 0%. (A still-active partial learner
			// is unaffected: their bar is computed normally by the progress import.)
			$backfill['progress_pct'] = 100;
		}

		if ( $backfill ) {
			\Learnomy\Models\Enrollment::update( $enrollment_id, $backfill );
		}

		return $enrollment_id;
	}

	/**
	 * Normalize a source date (Unix timestamp string OR a MySQL datetime) to the
	 * `Y-m-d H:i:s` form Learnomy stores. Empty / unparseable input returns ''.
	 *
	 * @param mixed $value Source date value.
	 * @return string
	 */
	private function normalize_datetime( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ctype_digit( $value ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value );
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
	}

	/**
	 * Mark ONE lesson complete for a user (the progress tier is per-user-lesson
	 * so each row is idempotent on its own). bypass_rules=true skips drip/quiz
	 * gates — the source already recorded the completion. mark_lesson_complete
	 * also recomputes the enrollment %, auto-completes the course, and fires the
	 * completion hooks (which can auto-issue a certificate). Returns the created
	 * progress row id so undo can delete exactly this row.
	 *
	 * @param array $p Normalized progress payload (user_id, lesson_ext_id).
	 * @return int Progress row id, or 0 to skip.
	 */
	private function import_progress( array $p ): int {
		$user_id = (int) ( $p['user_id'] ?? 0 );
		$ext_id  = $p['lesson_ext_id'] ?? '';
		// A completed item may be a lesson OR a topic (sub_lesson). Both are stored
		// in lrn_lessons, but the id-map keys them under different entities — resolve
		// under 'lesson' first, then 'sub_lesson', so completed-topic progress is not
		// silently dropped.
		$lesson_id = (int) ( $this->map->resolve( $this->source->slug(), 'lesson', $ext_id ) ?? 0 );
		if ( ! $lesson_id ) {
			$lesson_id = (int) ( $this->map->resolve( $this->source->slug(), 'sub_lesson', $ext_id ) ?? 0 );
		}
		if ( $user_id <= 0 || $lesson_id <= 0 || ! get_userdata( $user_id ) ) {
			return 0;
		}

		( new \Learnomy\Services\Progress_Service() )->mark_lesson_complete( $user_id, $lesson_id, true );

		$row = \Learnomy\Models\Progress::get_for_user_and_item( $user_id, 'lesson', $lesson_id );
		return $row ? (int) $row->id : 0;
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Find-or-create per-type tags and assign them to an object.
	 *
	 * @param int    $object_id Target object id.
	 * @param string $type      Tag type (course/lesson/quiz/...).
	 * @param array  $names     Tag names to assign.
	 * @return void
	 */
	private function assign_tags( int $object_id, string $type, array $names ): void {
		$names = array_filter( array_map( 'trim', $names ) );
		if ( empty( $names ) ) {
			return;
		}
		$tag_ids = array();
		foreach ( $names as $name ) {
			$slug      = sanitize_title( $name );
			$existing  = Tag::find_by_slug_and_type( $slug, $type );
			$tag_ids[] = $existing
				? (int) $existing->id
				: (int) Tag::create(
					array(
						'name' => $name,
						'slug' => $slug,
						'type' => $type,
					)
				);
		}
		Tag::sync_object_tags( $object_id, $type, array_filter( $tag_ids ) );
	}

	/**
	 * Append source text blocks as titled sections to the course content.
	 *
	 * @param string $content Existing course content HTML.
	 * @param array  $blocks  Map of heading => lines[].
	 * @return string
	 */
	private function append_text_blocks( string $content, array $blocks ): string {
		foreach ( $blocks as $heading => $lines ) {
			$lines = array_filter( array_map( 'trim', (array) $lines ) );
			if ( empty( $lines ) ) {
				continue;
			}
			$content .= "\n\n<h3>" . esc_html( (string) $heading ) . "</h3>\n<ul>";
			foreach ( $lines as $line ) {
				$content .= '<li>' . esc_html( $line ) . '</li>';
			}
			$content .= '</ul>';
		}
		return $content;
	}

	/**
	 * Normalize a source status to Learnomy's published/draft.
	 *
	 * @param mixed $status Source status value.
	 * @return string
	 */
	private function norm_status( $status ): string {
		$status = (string) $status;
		if ( 'publish' === $status || 'published' === $status ) { // publish-status-ok — heal foreign WP-ism to canonical.
			return 'published';
		}
		return 'draft';
	}
}
