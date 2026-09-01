<?php
/**
 * Admin lesson editor view.
 *
 * Surfaces the full `lrn_lessons` data model so instructors can set the
 * course, section, parent lesson (sub-lesson / topic), sort order, content
 * type, duration and preview flag directly from the standalone admin form —
 * not just from within the Course Builder context.
 *
 * Layout mirrors the Course form: a dominant main column for every
 * authoring field (metadata IS the instructor's work for a lesson) and a
 * slim Publish-only sidebar on the right.
 *
 * @package Learnomy
 * @var object|null $lesson     Lesson row or null when creating a new lesson.
 * @var bool        $is_edit    Whether editing an existing lesson.
 * @var object[]    $categories All course categories (unused on this page but available).
 * @var object[]    $courses    All courses the current user can manage.
 * @var object[]    $sections   Sections for the current lesson's course (or empty on new).
 * @var object[]    $parent_choices Candidate parent lessons for the current section.
 */

defined( 'ABSPATH' ) || exit;

$page_title = $is_edit
	/* translators: %s: lesson title */
	? sprintf( __( 'Edit Lesson: %s', 'learnomy' ), esc_html( $lesson->title ) )
	: __( 'Add New Lesson', 'learnomy' );

$course       = null;
$section      = null;
$parent       = null;
$course_name  = '';
$section_name = '';
$parent_name  = '';

$current_course_id  = 0;
$current_section_id = 0;
$current_parent_id  = 0;

if ( $is_edit ) {
	$current_course_id  = (int) ( $lesson->course_id ?? 0 );
	$current_section_id = (int) ( $lesson->section_id ?? 0 );
	$current_parent_id  = (int) ( $lesson->parent_id ?? 0 );

	if ( $current_course_id ) {
		$course = \Learnomy\Models\Course::find( $current_course_id );
		if ( $course ) {
			$course_name = $course->title;
		}
	}
	if ( $current_section_id ) {
		$section = \Learnomy\Models\Section::find( $current_section_id );
		if ( $section ) {
			$section_name = $section->title;
		}
	}
	if ( $current_parent_id ) {
		$parent = \Learnomy\Models\Lesson::find( $current_parent_id );
		if ( $parent ) {
			$parent_name = $parent->title;
		}
	}
}

// Pre-select from query string when adding a lesson from a course/section context.
if ( ! $is_edit ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$current_course_id  = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
	$current_section_id = isset( $_GET['section_id'] ) ? absint( $_GET['section_id'] ) : 0;
	$current_parent_id  = isset( $_GET['parent_id'] ) ? absint( $_GET['parent_id'] ) : 0;
	// phpcs:enable

	if ( $current_course_id && empty( $course ) ) {
		$course = \Learnomy\Models\Course::find( $current_course_id );
	}
}

$current_status       = $is_edit ? ( $lesson->status ?? 'draft' ) : 'draft';
$current_content_type = $is_edit ? ( $lesson->content_type ?? 'text' ) : 'text';
$current_video_url    = $is_edit ? ( $lesson->video_url ?? '' ) : '';
$current_duration_sec = $is_edit ? (int) ( $lesson->duration_seconds ?? 0 ) : 0;
$current_sort_order   = $is_edit ? (int) ( $lesson->sort_order ?? 0 ) : 0;
$current_is_preview   = $is_edit ? (int) ( $lesson->is_preview ?? 0 ) : 0;

// Completion-rule columns added in Wave 1. Defaults mirror the schema so
// brand-new lessons render as "Manual" with no thresholds set.
$current_completion_mode        = $is_edit ? ( $lesson->completion_mode ?? 'manual' ) : 'manual';
$current_min_time_seconds       = $is_edit ? (int) ( $lesson->min_time_seconds ?? 0 ) : 0;
$current_min_video_pct          = $is_edit ? (int) ( $lesson->min_video_watch_pct ?? 0 ) : 0;
$current_require_scroll         = $is_edit ? (int) ( $lesson->require_scroll_end ?? 0 ) : 0;
$current_require_quiz           = $is_edit ? (int) ( $lesson->require_quiz_pass ?? 0 ) : 0;
$current_lock_next              = $is_edit ? (int) ( $lesson->lock_next_until_complete ?? 0 ) : 0;
$current_completion_enforcement = $is_edit ? (string) ( $lesson->completion_enforcement ?? '' ) : '';

// Comments override column — tri-state: NULL inherits from the parent course.
$current_comments_override = $is_edit ? ( $lesson->comments_enabled ?? null ) : null;

$min_time_mins = intdiv( $current_min_time_seconds, 60 );
$min_time_secs = $current_min_time_seconds % 60;

// Drip schedule column. Stored as UTC TIMESTAMP in DB; the
// <input type="datetime-local"> below works in the site timezone, so we
// convert UTC → site TZ for display and let the REST controller convert
// back to UTC on save.
$current_available_date = $is_edit ? ( $lesson->available_date ?? null ) : null;

/**
 * Stored UTC -> the site-local value an <input type="datetime-local"> expects.
 *
 * The exact inverse of the REST handler's local -> UTC conversion on save. One
 * shared converter each way, so a saved time cannot drift by the site's UTC
 * offset on every round trip.
 *
 * @param string|null $utc UTC datetime string from the lessons table.
 * @return string `Y-m-d\TH:i`, or '' when unset (so the field renders blank).
 */
$format_available_date_input = static function ( ?string $utc ): string {
	return \Learnomy\datetime_local_input( $utc );
};

$duration_mins = intdiv( $current_duration_sec, 60 );
$duration_secs = $current_duration_sec % 60;

// Existing attachments hydrated so the uploader can render its initial state
// without an extra REST round-trip. The uploader then manages state in JS.
$existing_attachments = $is_edit ? \Learnomy\Models\Lesson::get_attachments( (int) $lesson->id ) : [];

// Canonical content-type list — keyed label map consumed by the Content Type
// <select>. Reads through the model constant so the admin form, Pro builder,
// and REST whitelist all share one source of truth.
$content_type_labels = [
	'text'        => __( 'Text', 'learnomy' ),
	'video'       => __( 'Video', 'learnomy' ),
	'audio'       => __( 'Audio', 'learnomy' ),
	'pdf'         => __( 'PDF', 'learnomy' ),
	'embed'       => __( 'Embed', 'learnomy' ),
	'live'        => __( 'Live session', 'learnomy' ),
	'download'    => __( 'Download', 'learnomy' ),
	'mediashield' => __( 'Protected video (MediaShield)', 'learnomy' ),
];

// MediaShield is an optional companion plugin. Offer the protected-video
// content type only when it is active, so authors can't pick a delivery mode
// the site can't render. `post_type_exists` is the reliable activation signal
// (the CPT registers on init, before this admin page renders).
$mediashield_active = post_type_exists( 'mediashield_video' );

$content_type_options = [];
foreach ( \Learnomy\Models\Lesson::content_types() as $type_key ) {
	if ( 'mediashield' === $type_key && ! $mediashield_active ) {
		continue;
	}
	$content_type_options[ $type_key ] = $content_type_labels[ $type_key ] ?? \Learnomy\Models\Lesson::content_type_label( $type_key );
}

// Library of protected videos for the picker. Capped — a dropdown is the right
// control for a modest library; a site with thousands of protected videos
// would warrant a search-driven picker (future enhancement).
$mediashield_videos = $mediashield_active
	? get_posts(
		[
			'post_type'      => 'mediashield_video',
			'post_status'    => 'publish',
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded admin picker; large libraries get a search picker (see note above).
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		]
	)
	: [];

$base_edit_url = admin_url( 'admin.php?page=learnomy-edit-course&course_id=' );
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="book-text" class="lrn-page-header__icon"></i>
				<?php echo esc_html( $page_title ); ?>
			</h1>
			<?php if ( $is_edit && $course ) : ?>
				<nav class="lrn-breadcrumb" aria-label="<?php esc_attr_e( 'Lesson context', 'learnomy' ); ?>">
					<a href="<?php echo esc_url( $base_edit_url . absint( $course->id ) ); ?>" class="lrn-breadcrumb__item">
						<?php echo esc_html( $course->title ); ?>
					</a>
					<?php if ( $section ) : ?>
						<span class="lrn-breadcrumb__sep" aria-hidden="true">/</span>
						<span class="lrn-breadcrumb__item"><?php echo esc_html( $section->title ); ?></span>
					<?php endif; ?>
					<?php if ( $parent ) : ?>
						<span class="lrn-breadcrumb__sep" aria-hidden="true">/</span>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-lesson-editor&lesson_id=' . absint( $parent->id ) ) ); ?>" class="lrn-breadcrumb__item">
							<?php echo esc_html( $parent->title ); ?>
						</a>
					<?php endif; ?>
					<span class="lrn-breadcrumb__sep" aria-hidden="true">/</span>
					<span class="lrn-breadcrumb__item lrn-breadcrumb__item--current">
						<?php echo esc_html( $lesson->title ); ?>
					</span>
				</nav>
			<?php endif; ?>
		</div>
		<div class="lrn-page-header__actions">
			<?php if ( $is_edit && $course && ! empty( $course->slug ) && ! empty( $lesson->slug ) ) : ?>
				<a href="
				<?php
				echo esc_url(
					\Learnomy\route_url(
						'lesson',
						array(
							'slug'        => $course->slug,
							'lesson_slug' => $lesson->slug,
						)
					)
				);
				?>
							" class="lrn-admin-btn" target="_blank" rel="noopener">
					<i data-lucide="eye" aria-hidden="true"></i> <?php esc_html_e( 'View Lesson', 'learnomy' ); ?>
				</a>
			<?php endif; ?>
			<?php if ( $is_edit && $course ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-course-builder&course_id=' . $course->id ) ); ?>" class="lrn-admin-btn">
					<i data-lucide="arrow-left" aria-hidden="true"></i> <?php esc_html_e( 'Back to Course Builder', 'learnomy' ); ?>
				</a>
			<?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-lessons' ) ); ?>" class="lrn-admin-btn">
				<i data-lucide="arrow-left" aria-hidden="true"></i> <?php esc_html_e( 'Back to Lessons', 'learnomy' ); ?>
			</a>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<div class="lrn-course-form__layout" id="lrn-lesson-editor-wrap"
		data-lesson-id="<?php echo $is_edit ? absint( $lesson->id ) : 0; ?>"
		data-course-id="<?php echo absint( $current_course_id ); ?>"
		data-section-id="<?php echo absint( $current_section_id ); ?>"
		data-parent-id="<?php echo absint( $current_parent_id ); ?>">

		<!-- ── Main column: every authoring surface the instructor works in ── -->
		<div class="lrn-course-form__main">

			<!-- Title + URL slug (identity) -->
			<div class="lrn-admin-card">
				<div class="lrn-form-field lrn-form-field--wide">
					<label for="lrn-lesson-title">
						<i data-lucide="type" class="lrn-icon--sm"></i>
						<?php esc_html_e( 'Lesson Title', 'learnomy' ); ?>
						<span class="required" aria-hidden="true">*</span>
					</label>
					<input type="text" id="lrn-lesson-title" class="large-text lrn-input--lg" required
							value="<?php echo $is_edit ? esc_attr( $lesson->title ) : ''; ?>"
							placeholder="<?php esc_attr_e( 'Lesson title', 'learnomy' ); ?>">
				</div>
				<?php if ( $is_edit && $course && ! empty( $course->slug ) ) : ?>
					<div class="lrn-form-field lrn-form-field--wide">
						<label for="lrn-lesson-slug">
							<i data-lucide="link" class="lrn-icon--sm"></i>
							<?php esc_html_e( 'Lesson URL slug', 'learnomy' ); ?>
						</label>
						<input type="text" id="lrn-lesson-slug" name="slug" class="regular-text"
								value="<?php echo esc_attr( $lesson->slug ?? '' ); ?>"
								placeholder="<?php esc_attr_e( 'auto-generated from the title', 'learnomy' ); ?>">
						<p class="lrn-form-help">
							<?php
							// Pattern preview: the course segment comes from the registry
							// (route_url) so a course-slug change moves this with it; the
							// lesson segment is shown as a <slug> placeholder.
							echo esc_html(
								sprintf(
									/* translators: %s: the lesson URL pattern, e.g. /courses/react/lesson/<slug>/ */
									__( 'Front-end URL: %s. Leave blank to regenerate it from the title.', 'learnomy' ),
									trailingslashit( \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) ) ) . 'lesson/<' . esc_html__( 'slug', 'learnomy' ) . '>/'
								)
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<!--
			Lesson body (Editor.js). Available for every content type — the
			frontend renders this body for any lesson that has it (e.g. notes or
			a transcript below a video / protected video, a description under a
			PDF). For text lessons it is the primary content; for media lessons
			it is the supplementary copy shown beneath the player.
			-->
			<div class="lrn-admin-card lrn-lesson-editor__content-block" data-content-for="text,video,audio,pdf,embed,live,download,mediashield">
				<h3 class="lrn-admin-card__title">
					<i data-lucide="file-text" class="lrn-icon--sm"></i>
					<?php esc_html_e( 'Content', 'learnomy' ); ?>
				</h3>
				<div class="lrn-lesson-editor__editorjs" id="lrn-editor"></div>
			</div>

			<!-- Course placement -->
			<div class="lrn-admin-card">
				<h3 class="lrn-admin-card__title">
					<i data-lucide="list-tree" class="lrn-icon--sm"></i>
					<?php esc_html_e( 'Placement', 'learnomy' ); ?>
				</h3>

				<div class="lrn-form-grid">
					<div class="lrn-form-field">
						<label for="lrn-lesson-course"><?php esc_html_e( 'Course', 'learnomy' ); ?> <span class="lrn-required">*</span></label>
						<select id="lrn-lesson-course">
							<option value=""><?php esc_html_e( 'Select a course', 'learnomy' ); ?></option>
							<?php foreach ( $courses as $c ) : ?>
								<option value="<?php echo absint( $c->id ); ?>" <?php selected( $current_course_id, (int) $c->id ); ?>>
									<?php echo esc_html( $c->title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php if ( $is_edit && $current_course_id ) : ?>
							<p class="lrn-lesson-editor__help-text">
								<?php
								// The same move semantics as the builder's Attach
								// Existing: a lesson lives in ONE course, so changing
								// this removes it from its current one, silently,
								// unless we say so here (Basecamp 10153330214).
								esc_html_e( 'Changing the course moves this lesson: it leaves its current course and its old link changes. To reuse it in another course instead, use Attach Existing in that course builder and choose Copy.', 'learnomy' );
								?>
							</p>
						<?php endif; ?>
					</div>

					<div class="lrn-form-field">
						<label for="lrn-lesson-section"><?php esc_html_e( 'Section', 'learnomy' ); ?></label>
						<select id="lrn-lesson-section" <?php echo $current_course_id ? '' : 'disabled'; ?>>
							<option value=""><?php esc_html_e( 'No section (directly under the course)', 'learnomy' ); ?></option>
							<?php foreach ( $sections as $s ) : ?>
								<option value="<?php echo absint( $s->id ); ?>" <?php selected( $current_section_id, (int) $s->id ); ?>>
									<?php echo esc_html( $s->title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="lrn-lesson-editor__help-text"><?php esc_html_e( 'Optional. Pick a section, or leave blank to place the lesson directly under the course.', 'learnomy' ); ?></p>
					</div>
				</div>

				<div class="lrn-form-grid">
					<div class="lrn-form-field">
						<label for="lrn-lesson-parent"><?php esc_html_e( 'Parent Lesson', 'learnomy' ); ?></label>
						<select id="lrn-lesson-parent" <?php echo $current_section_id ? '' : 'disabled'; ?>>
							<option value=""><?php esc_html_e( 'None (top-level)', 'learnomy' ); ?></option>
							<?php foreach ( $parent_choices as $candidate ) : ?>
								<?php
								if ( $is_edit && (int) $candidate->id === (int) $lesson->id ) {
									continue; }
								?>
								<option value="<?php echo absint( $candidate->id ); ?>" <?php selected( $current_parent_id, (int) $candidate->id ); ?>>
									<?php echo esc_html( $candidate->title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="lrn-lesson-editor__help-text"><?php esc_html_e( 'Attach as a sub-lesson (topic) beneath another lesson. Sub-lessons inherit their parent\'s section.', 'learnomy' ); ?></p>
					</div>

					<div class="lrn-form-field">
						<label for="lrn-lesson-sort-order"><?php esc_html_e( 'Sort order', 'learnomy' ); ?></label>
						<input type="number" id="lrn-lesson-sort-order" class="small-text" min="0" step="1"
								value="<?php echo esc_attr( $current_sort_order ); ?>">
						<p class="lrn-lesson-editor__help-text"><?php esc_html_e( 'Leave 0 to append at the end.', 'learnomy' ); ?></p>
					</div>
				</div>
			</div>

			<!-- Lesson Settings -->
			<div class="lrn-admin-card">
				<h3 class="lrn-admin-card__title">
					<i data-lucide="settings" class="lrn-icon--sm"></i>
					<?php esc_html_e( 'Lesson Settings', 'learnomy' ); ?>
				</h3>

				<div class="lrn-form-grid">
					<div class="lrn-form-field">
						<label for="lrn-lesson-content-type"><?php esc_html_e( 'Content Type', 'learnomy' ); ?></label>
						<select id="lrn-lesson-content-type">
							<?php foreach ( $content_type_options as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_content_type, $val ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="lrn-lesson-editor__help-text"><?php esc_html_e( 'Pick how this lesson is delivered. The form reveals only the fields relevant to the chosen type.', 'learnomy' ); ?></p>
					</div>

					<?php
					/*
					 * Hidden for live sessions: the live block below carries "Runs for",
					 * and two "how long is this" fields on one form is how a 45-minute
					 * session ended up with a Duration of zero. `data-content-not-for`
					 * is the inverse of the `data-content-for` blocks use.
					 */
					?>
					<div class="lrn-form-field" data-content-not-for="live">
						<label for="lrn-lesson-duration-mins"><?php esc_html_e( 'Duration', 'learnomy' ); ?></label>
						<div class="lrn-duration-input">
							<input type="number" id="lrn-lesson-duration-mins" class="small-text" min="0" step="1"
									value="<?php echo esc_attr( $duration_mins ); ?>"
									aria-label="<?php esc_attr_e( 'Duration, minutes', 'learnomy' ); ?>">
							<span><?php esc_html_e( 'min', 'learnomy' ); ?></span>
							<input type="number" id="lrn-lesson-duration-secs" class="small-text" min="0" max="59" step="1"
									value="<?php echo esc_attr( $duration_secs ); ?>"
									aria-label="<?php esc_attr_e( 'Duration, seconds', 'learnomy' ); ?>">
							<span><?php esc_html_e( 'sec', 'learnomy' ); ?></span>
						</div>
						<p class="lrn-lesson-editor__help-text"><?php esc_html_e( 'How long this lesson takes. Students see it beside the lesson in the course curriculum, and it adds up to the course total. Leave it at zero and no time is shown.', 'learnomy' ); ?></p>
					</div>
				</div>

				<div class="lrn-form-field lrn-form-field--wide lrn-lesson-editor__content-block" data-content-for="video">
					<label for="lrn-lesson-video-url"><?php esc_html_e( 'Video URL', 'learnomy' ); ?></label>
					<input type="url" id="lrn-lesson-video-url" class="regular-text"
							value="<?php echo esc_url( $current_video_url ); ?>"
							placeholder="https://youtube.com/watch?v=…">
					<p class="lrn-lesson-editor__help-text"><?php esc_html_e( 'YouTube, Vimeo, Wistia, Bunny, self-hosted MP4, S3, or HLS. The provider is detected automatically.', 'learnomy' ); ?></p>
					<p class="lrn-lesson-editor__provider-badge" id="lrn-lesson-provider-badge" hidden></p>
				</div>

				<?php
				/*
				 * Live session fields.
				 *
				 * These used to not exist. Choosing "Live session" showed the author a
				 * field labelled "Video URL" (help text: YouTube, Vimeo, HLS) to paste a
				 * Zoom link into, and NO start-time field at all — the session start was
				 * silently read out of the Schedule card's "Available date", whose own
				 * label says it LOCKS the lesson. Following the labels produced a session
				 * with no start; discovering the hidden behaviour produced a session
				 * locked to every student until the moment it began.
				 *
				 * The join link still saves to the same `video_url` column (one media URL
				 * per lesson — the shared-column pattern is fine). It gets its own input
				 * here purely so the label and help text tell the truth.
				 */
				$lrn_live_start   = $is_edit ? ( $lesson->scheduled_start_at ?? null ) : null;
				$lrn_live_minutes = $is_edit ? (int) ( $lesson->live_duration_minutes ?? 0 ) : 0;
				?>
				<div class="lrn-form-field lrn-form-field--wide lrn-lesson-editor__content-block" data-content-for="live">
					<label for="lrn-lesson-live-join-url"><?php esc_html_e( 'Join link', 'learnomy' ); ?></label>
					<input type="url" id="lrn-lesson-live-join-url" class="regular-text"
							value="<?php echo esc_url( 'live' === $current_content_type ? $current_video_url : '' ); ?>"
							placeholder="https://zoom.us/j/… or https://meet.google.com/…">
					<p class="lrn-lesson-editor__help-text">
						<?php esc_html_e( 'Paste the meeting link from Zoom, Google Meet, Teams, or whatever you host the session on. Students see a Join button once the session starts.', 'learnomy' ); ?>
					</p>
				</div>

				<div class="lrn-form-field lrn-lesson-editor__content-block" data-content-for="live">
					<label for="lrn-lesson-live-start"><?php esc_html_e( 'Starts at', 'learnomy' ); ?></label>
					<input type="datetime-local" id="lrn-lesson-live-start" class="lrn-input"
							value="<?php echo esc_attr( $format_available_date_input( $lrn_live_start ) ); ?>">
					<p class="lrn-lesson-editor__help-text">
						<?php
						printf(
							/* translators: %s: the site's timezone, e.g. "Europe/Berlin" or "UTC+2". */
							esc_html__( 'When the session takes place, in your site timezone (%s). Before it starts, students see a countdown. Leave blank for an always-open room.', 'learnomy' ),
							esc_html( wp_timezone_string() )
						);
						?>
					</p>
				</div>

				<div class="lrn-form-field lrn-lesson-editor__content-block" data-content-for="live">
					<label for="lrn-lesson-live-duration"><?php esc_html_e( 'Runs for', 'learnomy' ); ?></label>
					<input type="number" id="lrn-lesson-live-duration" class="lrn-input lrn-input--sm"
							min="1" max="1440" step="5"
							value="<?php echo esc_attr( $lrn_live_minutes > 0 ? (string) $lrn_live_minutes : '' ); ?>"
							placeholder="60">
					<span class="lrn-lesson-editor__field-suffix"><?php esc_html_e( 'minutes', 'learnomy' ); ?></span>
					<p class="lrn-lesson-editor__help-text">
						<?php esc_html_e( 'How long the Join button stays available after the session starts. Defaults to 60 minutes.', 'learnomy' ); ?>
					</p>
				</div>

				<div class="lrn-form-field lrn-form-field--wide lrn-lesson-editor__content-block" data-content-for="live">
					<p class="lrn-lesson-editor__summary" id="lrn-lesson-live-summary" role="status"></p>
				</div>

				<?php if ( $mediashield_active ) : ?>
				<div class="lrn-form-field lrn-form-field--wide lrn-lesson-editor__content-block" data-content-for="mediashield">
					<label for="lrn-lesson-mediashield-id"><?php esc_html_e( 'Protected video', 'learnomy' ); ?></label>
					<select id="lrn-lesson-mediashield-id" class="regular-text">
						<option value=""><?php esc_html_e( 'Select a MediaShield video', 'learnomy' ); ?></option>
						<?php
						$current_mediashield_id = ( 'mediashield' === $current_content_type ) ? (int) $current_video_url : 0;
						foreach ( $mediashield_videos as $ms_video ) :
							?>
							<option value="<?php echo esc_attr( (string) $ms_video->ID ); ?>" <?php selected( $current_mediashield_id, (int) $ms_video->ID ); ?>>
								<?php echo esc_html( $ms_video->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="lrn-lesson-editor__help-text">
						<?php esc_html_e( 'Pick a protected video from your MediaShield library. It plays in the protected player, with sponsor and ad surfaces around it.', 'learnomy' ); ?>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mediashield_video' ) ); ?>"><?php esc_html_e( 'Manage videos', 'learnomy' ); ?></a>
					</p>
				</div>
				<?php endif; ?>

				<?php
				/*
				Media-file field — content-type drives the label + button text + picker mode.
						File-based types (audio / pdf / download) get a big primary "Choose file"
						button with the selected filename below. Embed type swaps to a URL-only
						input. The single hidden `#lrn-lesson-media-url` carries the value to save. */
				?>
				<div class="lrn-form-field lrn-form-field--wide lrn-lesson-editor__content-block" data-content-for="audio,pdf,embed,download">
					<label for="lrn-lesson-media-url" id="lrn-lesson-media-url-label"><?php esc_html_e( 'Media file', 'learnomy' ); ?></label>

					<div class="lrn-lesson-editor__media-picker" id="lrn-lesson-media-picker">
						<button type="button" class="lrn-admin-btn lrn-admin-btn--primary lrn-lesson-editor__media-picker-btn" id="lrn-lesson-media-url-pick">
							<?php esc_html_e( 'Choose file', 'learnomy' ); ?>
						</button>
						<div class="lrn-lesson-editor__media-picker-selected" id="lrn-lesson-media-picker-selected" hidden>
							<span class="lrn-lesson-editor__media-picker-filename" id="lrn-lesson-media-picker-filename"></span>
							<button type="button" class="lrn-admin-btn lrn-admin-btn--link" id="lrn-lesson-media-url-clear"><?php esc_html_e( 'Remove', 'learnomy' ); ?></button>
						</div>
					</div>

					<div class="lrn-lesson-editor__media-embed" id="lrn-lesson-media-embed" hidden>
						<input type="url" id="lrn-lesson-media-embed-url" class="regular-text"
								value=""
								placeholder="https://www.figma.com/embed?... or https://codesandbox.io/embed/…">
					</div>

					<input type="hidden" id="lrn-lesson-media-url" value="<?php echo esc_url( $current_video_url ); ?>">

					<p class="lrn-lesson-editor__help-text" id="lrn-lesson-media-url-help"></p>
				</div>

				<?php
				/**
				 * Render seam for a Pro-registered content-type's editor panel
				 * (e.g. the SCORM upload field). Echo a
				 * `.lrn-lesson-editor__content-block` with `data-content-for="<type>"`
				 * so the editor JS shows/hides it alongside the built-in blocks.
				 *
				 * @since 1.3.1
				 *
				 * @param string $content_type The currently-selected content-type.
				 * @param int    $lesson_id    The lesson id (0 for a new lesson).
				 */
				do_action( 'learnomy_lesson_content_editor', $current_content_type, $is_edit && $lesson ? (int) $lesson->id : 0 );
				?>

				<div class="lrn-form-grid">
					<div class="lrn-form-field">
						<label for="lrn-lesson-is-preview"><?php esc_html_e( 'Preview', 'learnomy' ); ?></label>
						<label class="lrn-form-field__checkbox-row">
							<input type="checkbox" id="lrn-lesson-is-preview" value="1" <?php checked( $current_is_preview, 1 ); ?>>
							<span><?php esc_html_e( 'Allow non-enrolled users to preview this lesson.', 'learnomy' ); ?></span>
						</label>
					</div>

					<?php
					// The global `enable_lesson_comments` master kill-switch (Learnomy
					// Settings) beats every per-course/per-lesson choice — when it is off
					// this override is inert (Lesson::comments_enabled() returns false
					// regardless). Hide it rather than imply it can re-enable comments
					// (Basecamp 10127776404). Absent key = default ON, mirroring the model.
					$lrn_global_settings    = get_option( 'learnomy_settings', array() );
					$lrn_lesson_comments_on = ! (
						is_array( $lrn_global_settings )
						&& array_key_exists( 'enable_lesson_comments', $lrn_global_settings )
						&& empty( $lrn_global_settings['enable_lesson_comments'] )
					);
					if ( $lrn_lesson_comments_on ) :
						?>
					<!-- Comments override — tri-state:
						"inherit" (value=inherit) keeps the parent course's setting (the column is stored NULL);
						"on"/"off" persists an explicit override. -->
					<div class="lrn-form-field">
						<label for="lrn-lesson-comments-enabled"><?php esc_html_e( 'Comments', 'learnomy' ); ?></label>
						<select id="lrn-lesson-comments-enabled"
								data-current="<?php echo esc_attr( null === $current_comments_override ? 'inherit' : ( (int) $current_comments_override ? 'on' : 'off' ) ); ?>">
							<option value="inherit" <?php selected( null === $current_comments_override ); ?>><?php esc_html_e( 'Inherit from course', 'learnomy' ); ?></option>
							<option value="on" <?php selected( null !== $current_comments_override && (int) $current_comments_override === 1 ); ?>><?php esc_html_e( 'On (override course setting)', 'learnomy' ); ?></option>
							<option value="off" <?php selected( null !== $current_comments_override && (int) $current_comments_override === 0 ); ?>><?php esc_html_e( 'Off (override course setting)', 'learnomy' ); ?></option>
						</select>
						<p class="lrn-lesson-editor__help-text"><?php esc_html_e( 'Default is to inherit the course-level comments setting. Pick On or Off to override for just this lesson.', 'learnomy' ); ?></p>
					</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Completion rules (task #61) ────────────────────────────────
				Picks one of the six completion modes and then reveals only
				the thresholds relevant to that mode. The JS store below
				collects every visible threshold on save so the REST payload
				matches whatever the instructor sees. -->
			<div class="lrn-admin-card lrn-lesson-completion-card" id="lrn-lesson-completion-card">
				<h3 class="lrn-admin-card__title">
					<i data-lucide="check-circle-2" class="lrn-icon--sm"></i>
					<?php esc_html_e( 'Completion Rules', 'learnomy' ); ?>
				</h3>
				<p class="lrn-lesson-editor__help-text">
					<?php esc_html_e( 'Choose how this lesson is marked complete. Auto modes fire the moment the student meets the threshold.', 'learnomy' ); ?>
				</p>

				<div class="lrn-form-field lrn-form-field--wide">
					<label for="lrn-lesson-completion-mode"><?php esc_html_e( 'Completion rule', 'learnomy' ); ?></label>
					<select id="lrn-lesson-completion-mode">
						<option value="manual" <?php selected( $current_completion_mode, 'manual' ); ?>><?php esc_html_e( 'Manual (student clicks Mark Complete)', 'learnomy' ); ?></option>
						<option value="auto_time" <?php selected( $current_completion_mode, 'auto_time' ); ?>><?php esc_html_e( 'Auto: time on page', 'learnomy' ); ?></option>
						<option value="auto_video" <?php selected( $current_completion_mode, 'auto_video' ); ?>><?php esc_html_e( 'Auto: video watched', 'learnomy' ); ?></option>
						<option value="auto_scroll" <?php selected( $current_completion_mode, 'auto_scroll' ); ?>><?php esc_html_e( 'Auto: scroll to end', 'learnomy' ); ?></option>
						<option value="auto_quiz_pass" <?php selected( $current_completion_mode, 'auto_quiz_pass' ); ?>><?php esc_html_e( 'Auto: quiz pass', 'learnomy' ); ?></option>
						<option value="auto_standard" <?php selected( $current_completion_mode, 'auto_standard' ); ?>><?php esc_html_e( 'Auto: SCORM / standards package reports complete', 'learnomy' ); ?></option>
						<option value="combined" <?php selected( $current_completion_mode, 'combined' ); ?>><?php esc_html_e( 'All of the above (combined)', 'learnomy' ); ?></option>
					</select>
				</div>

				<div class="lrn-form-field lrn-form-field--wide lrn-lesson-completion__rule" data-rule-for="auto_time,combined">
					<label for="lrn-lesson-min-time-mins"><?php esc_html_e( 'Minimum time on page', 'learnomy' ); ?></label>
					<div class="lrn-duration-input">
						<input type="number" id="lrn-lesson-min-time-mins" class="small-text" min="0" step="1"
								value="<?php echo esc_attr( $min_time_mins ); ?>">
						<span><?php esc_html_e( 'min', 'learnomy' ); ?></span>
						<input type="number" id="lrn-lesson-min-time-secs" class="small-text" min="0" max="59" step="1"
								value="<?php echo esc_attr( $min_time_secs ); ?>">
						<span><?php esc_html_e( 'sec', 'learnomy' ); ?></span>
					</div>
				</div>

				<div class="lrn-form-field lrn-form-field--wide lrn-lesson-completion__rule" data-rule-for="auto_video,combined" data-rule-requires-video="1">
					<label for="lrn-lesson-min-video-pct"><?php esc_html_e( 'Minimum video watch %', 'learnomy' ); ?></label>
					<div class="lrn-lesson-completion__slider">
						<input type="range" id="lrn-lesson-min-video-pct" min="0" max="100" step="1"
								value="<?php echo esc_attr( $current_min_video_pct ); ?>">
						<output for="lrn-lesson-min-video-pct" id="lrn-lesson-min-video-pct-output"><?php echo esc_html( (string) $current_min_video_pct ); ?></output>
					</div>
					<p class="lrn-lesson-editor__help-text"><?php esc_html_e( 'Only applied when the content type is Video, Embed, or Live.', 'learnomy' ); ?></p>
				</div>

				<div class="lrn-form-field lrn-form-field--wide lrn-form-field--checkbox lrn-lesson-completion__rule" data-rule-for="auto_scroll,combined">
					<label for="lrn-lesson-require-scroll-end">
						<input type="checkbox" id="lrn-lesson-require-scroll-end" value="1" <?php checked( $current_require_scroll, 1 ); ?>>
						<?php esc_html_e( 'Require scroll to end', 'learnomy' ); ?>
					</label>
				</div>

				<div class="lrn-form-field lrn-form-field--wide lrn-form-field--checkbox lrn-lesson-completion__rule" data-rule-for="auto_quiz_pass,combined">
					<label for="lrn-lesson-require-quiz-pass">
						<input type="checkbox" id="lrn-lesson-require-quiz-pass" value="1" <?php checked( $current_require_quiz, 1 ); ?>>
						<?php esc_html_e( 'Quiz must be passed', 'learnomy' ); ?>
					</label>
					<p class="lrn-lesson-editor__help-text"><?php esc_html_e( 'Requires a quiz linked to this lesson. Students cannot mark the lesson complete until they pass.', 'learnomy' ); ?></p>
				</div>

				<div class="lrn-form-field lrn-form-field--wide lrn-form-field--checkbox">
					<label for="lrn-lesson-lock-next">
						<input type="checkbox" id="lrn-lesson-lock-next" value="1" <?php checked( $current_lock_next, 1 ); ?>>
						<?php esc_html_e( 'Lock next lesson until complete', 'learnomy' ); ?>
					</label>
				</div>

				<div class="lrn-form-field lrn-form-field--wide">
					<label for="lrn-lesson-completion-enforcement"><?php esc_html_e( 'Enforcement override', 'learnomy' ); ?></label>
					<select id="lrn-lesson-completion-enforcement">
						<option value="" <?php selected( $current_completion_enforcement, '' ); ?>>
							<?php esc_html_e( 'Inherit (use course or site setting)', 'learnomy' ); ?>
						</option>
						<option value="strict" <?php selected( $current_completion_enforcement, 'strict' ); ?>>
							<?php esc_html_e( 'Strict: rules must be met', 'learnomy' ); ?>
						</option>
						<option value="advisory" <?php selected( $current_completion_enforcement, 'advisory' ); ?>>
							<?php esc_html_e( 'Advisory: rules shown but optional', 'learnomy' ); ?>
						</option>
						<option value="off" <?php selected( $current_completion_enforcement, 'off' ); ?>>
							<?php esc_html_e( 'Off: no rules, Complete always available', 'learnomy' ); ?>
						</option>
					</select>
					<p class="lrn-lesson-editor__help-text">
						<?php esc_html_e( 'Override the site-wide enforcement just for this lesson. Useful when a single lesson should not enforce video-watch / time rules even though others do.', 'learnomy' ); ?>
					</p>
				</div>
			</div>

			<!-- Multi-resource attachments (task #63) ──────────────────────
				Uploader uses wp.media so instructors can attach multiple
				files at once. Existing attachments are rendered server-side
				and can be reordered (drag) or removed.
				The JS store persists adds / removes / reorders via the
				/lessons/:id/attachments REST sub-resource. -->
			<div class="lrn-admin-card lrn-lesson-attachments-card" id="lrn-lesson-attachments-card"
					data-lesson-id="<?php echo $is_edit ? absint( $lesson->id ) : 0; ?>">
				<h3 class="lrn-admin-card__title">
					<i data-lucide="paperclip" class="lrn-icon--sm"></i>
					<?php esc_html_e( 'Resources', 'learnomy' ); ?>
				</h3>
				<p class="lrn-lesson-editor__help-text">
					<?php esc_html_e( 'Attach downloadable resources students can access on the lesson page. Drag to reorder.', 'learnomy' ); ?>
				</p>

				<?php if ( ! $is_edit ) : ?>
					<p class="lrn-lesson-attachments__hint">
						<?php esc_html_e( 'Save the lesson first to enable attachments.', 'learnomy' ); ?>
					</p>
				<?php else : ?>
					<ul class="lrn-lesson-attachments__list" id="lrn-lesson-attachments-list" role="list">
						<?php foreach ( $existing_attachments as $row ) : ?>
							<li class="lrn-lesson-attachments__item"
									data-attachment-id="<?php echo absint( $row->id ); ?>"
									draggable="true">
								<span class="lrn-lesson-attachments__handle" aria-hidden="true">
									<i data-lucide="grip-vertical"></i>
								</span>
								<span class="lrn-lesson-attachments__filename">
									<?php echo esc_html( $row->filename ?: sprintf( '#%d', absint( $row->media_id ) ) ); ?>
								</span>
								<span class="lrn-lesson-attachments__size">
									<?php echo esc_html( size_format( (int) $row->size_bytes ) ?: '' ); ?>
								</span>
								<button type="button" class="lrn-lesson-attachments__remove button-link-delete"
										aria-label="<?php esc_attr_e( 'Remove attachment', 'learnomy' ); ?>">
									<i data-lucide="x"></i>
								</button>
							</li>
						<?php endforeach; ?>
					</ul>
					<button type="button" class="lrn-admin-btn" id="lrn-lesson-attachments-upload">
						<i data-lucide="upload" aria-hidden="true"></i>
						<?php esc_html_e( 'Add files', 'learnomy' ); ?>
					</button>
					<span class="lrn-lesson-attachments__status" id="lrn-lesson-attachments-status" aria-live="polite"></span>
				<?php endif; ?>
			</div>

			<?php
			/**
			 * Renders extra lesson-editor fields (and sub-lesson, same editor) so
			 * an integration can add its own panel/widget without forking this
			 * template — e.g. WP Fusion "apply tags / required tags" for this
			 * lesson. Persist on the matching `learnomy_lesson_saved` action.
			 *
			 * @param object|null $lesson The lesson being edited (null on create).
			 */
			do_action( 'learnomy_lesson_editor_fields', $is_edit && $lesson ? $lesson : null );
			?>

		</div><!-- /.lrn-course-form__main -->

		<!-- ── Sidebar: publish + compact meta/availability controls (Publish,
			Schedule, Release schedule, Details, Tags). These are short, set-and
			-forget controls; keeping them out of the main column keeps the
			authoring surface focused and shorter. ── -->
		<div class="lrn-course-form__sidebar">

			<div class="lrn-admin-card">
				<h3 class="lrn-admin-card__title">
					<i data-lucide="send" class="lrn-icon--sm"></i>
					<?php esc_html_e( 'Publish', 'learnomy' ); ?>
				</h3>
				<div class="lrn-form-field">
					<label for="lrn-lesson-status"><?php esc_html_e( 'Status', 'learnomy' ); ?></label>
					<select id="lrn-lesson-status">
						<option value="draft" <?php selected( $current_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'learnomy' ); ?></option>
						<option value="published" <?php selected( $current_status, 'published' ); ?>><?php esc_html_e( 'Published', 'learnomy' ); ?></option>
					</select>
				</div>
				<div class="lrn-form-actions">
					<button type="button" class="lrn-admin-btn lrn-admin-btn--primary lrn-admin-btn--full" id="lrn-save-lesson">
						<i data-lucide="save" aria-hidden="true"></i>
						<?php echo $is_edit ? esc_html__( 'Update Lesson', 'learnomy' ) : esc_html__( 'Save Lesson', 'learnomy' ); ?>
					</button>
					<span id="lrn-autosave-status" class="lrn-editor-autosave"></span>
					<span class="spinner" id="lrn-save-spinner"></span>
				</div>
				<?php if ( $is_edit ) : ?>
					<div class="lrn-form-actions lrn-form-actions--secondary">
						<button type="button" class="lrn-admin-btn lrn-admin-btn--danger lrn-admin-btn--full" id="lrn-delete-lesson"
								data-lesson-id="<?php echo absint( $lesson->id ); ?>"
								data-course-id="<?php echo absint( $lesson->course_id ); ?>">
							<i data-lucide="trash-2" aria-hidden="true"></i>
							<?php esc_html_e( 'Delete Lesson', 'learnomy' ); ?>
						</button>
					</div>
				<?php endif; ?>
				<div class="lrn-lesson-editor__toast" id="lrn-toast" role="status" aria-live="polite"></div>
			</div>

			<!-- Schedule (Free fixed-date drip) — compact availability control,
				kept in the sidebar so it doesn't stretch the main authoring
				column. Empty value = unlock immediately; site TZ → UTC on save. -->
			<div class="lrn-admin-card lrn-lesson-editor__schedule">
				<h3 class="lrn-admin-card__title">
					<i data-lucide="calendar-clock" class="lrn-icon--sm" aria-hidden="true"></i>
					<?php esc_html_e( 'Schedule', 'learnomy' ); ?>
				</h3>

				<?php
				// `drip_mode` decides which of the two schedule controls is in
				// force, and Lesson_Service::evaluate_availability() reads them in
				// an elseif chain: on 'relative' it consults the per-student
				// unlock row and never looks at `available_date` at all. So an
				// author who set a release schedule of "14 days after enrollment"
				// and ALSO filled this box got a date that did nothing, under help
				// text promising it would "lock this lesson until" then - verified
				// on lms.local, where a relative lesson with an available_date of
				// 2031 opened for a student today.
				//
				// The panels are not duplicates and neither should be removed
				// (Basecamp 10203589087): this one is Free's fixed calendar date
				// and works with no Pro installed, while Release schedule adds
				// per-student offsets and prerequisites. They are two editors for
				// one column set, and the only thing missing was saying so at the
				// moment they disagree. Read from Free's own column, so this needs
				// no knowledge of which extension wrote it.
				$lrn_drip_mode     = $is_edit && $lesson ? (string) ( $lesson->drip_mode ?? 'fixed' ) : 'fixed';
				$lrn_drip_relative = 'relative' === $lrn_drip_mode;
				?>
				<?php if ( $lrn_drip_relative ) : ?>
					<p class="lrn-lesson-editor__help-text lrn-lesson-editor__notice">
						<i data-lucide="info" class="lrn-icon--sm" aria-hidden="true"></i>
						<?php esc_html_e( 'This lesson uses a release schedule counted from each student\'s enrollment, so the fixed date below is not applied. Clear the release schedule to use a fixed date instead.', 'learnomy' ); ?>
					</p>
				<?php endif; ?>

				<div class="lrn-form-field lrn-form-field--wide">
					<label for="lrn-lesson-available-date">
						<?php esc_html_e( 'Available date', 'learnomy' ); ?>
					</label>
					<input
						type="datetime-local"
						id="lrn-lesson-available-date"
						name="available_date"
						class="lrn-input"
						value="<?php echo esc_attr( $format_available_date_input( $current_available_date ) ); ?>"
					/>
					<?php if ( ! $lrn_drip_relative ) : ?>
						<p class="lrn-lesson-editor__help-text">
							<?php esc_html_e( 'Lock this lesson until the specified date and time. Leave blank to unlock immediately on enrollment.', 'learnomy' ); ?>
						</p>
					<?php endif; ?>
					<p class="lrn-lesson-editor__help-text lrn-lesson-editor__content-block" data-content-for="live">
						<?php esc_html_e( 'This is not the session start - set that under "Starts at" above. Leave this blank so students can open the lesson early and see the countdown; they cannot join before it begins in any case.', 'learnomy' ); ?>
					</p>
					<p class="lrn-lesson-editor__help-text">
						<?php esc_html_e( 'Admins and the course author always see the lesson unlocked, regardless of this setting.', 'learnomy' ); ?>
					</p>
				</div>
			</div>

			<?php
			/**
			 * Release / drip scheduling panel injection point.
			 *
			 * The Pro content-drip extension hooks here to render its release
			 * card. Now in the sidebar alongside the Free Schedule control so
			 * the availability settings group together and the main authoring
			 * column stays shorter.
			 *
			 * @param int $lesson_id Current lesson id, or 0 for new lessons.
			 */
			do_action( 'learnomy_lesson_release_settings', $is_edit && $lesson ? (int) $lesson->id : 0 );
			?>

			<?php if ( $is_edit ) : ?>
				<!-- Details — read-only metadata so admins can see at a glance what
					this lesson is, when it changed, and who authored it. -->
				<div class="lrn-admin-card">
					<h3 class="lrn-admin-card__title">
						<i data-lucide="info" class="lrn-icon--sm"></i>
						<?php esc_html_e( 'Details', 'learnomy' ); ?>
					</h3>
					<dl class="lrn-meta-list">
						<div class="lrn-meta-list__row">
							<dt><?php esc_html_e( 'Lesson ID', 'learnomy' ); ?></dt>
							<dd><?php echo absint( $lesson->id ); ?></dd>
						</div>
						<div class="lrn-meta-list__row">
							<dt><?php esc_html_e( 'Content type', 'learnomy' ); ?></dt>
							<dd><?php echo esc_html( $content_type_options[ $current_content_type ] ?? $current_content_type ); ?></dd>
						</div>
						<?php if ( $lesson_author_name ) : ?>
							<div class="lrn-meta-list__row">
								<dt><?php esc_html_e( 'Author', 'learnomy' ); ?></dt>
								<dd><?php echo esc_html( $lesson_author_name ); ?></dd>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $lesson->created_at ) && '0000-00-00 00:00:00' !== $lesson->created_at ) : ?>
							<div class="lrn-meta-list__row">
								<dt><?php esc_html_e( 'Created', 'learnomy' ); ?></dt>
								<dd><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $lesson->created_at . ' UTC' ) ) ); ?></dd>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $lesson->updated_at ) && '0000-00-00 00:00:00' !== $lesson->updated_at ) : ?>
							<div class="lrn-meta-list__row">
								<dt><?php esc_html_e( 'Last updated', 'learnomy' ); ?></dt>
								<dd><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $lesson->updated_at . ' UTC' ) ) ); ?></dd>
							</div>
						<?php endif; ?>
					</dl>
				</div>

				<?php
				// Tag taxonomies — one checklist card per registered lesson
				// taxonomy. Site owners add more via `learnomy_lesson_taxonomies`.
				// All post through `tag_ids[]`; the controller maps them to the
				// lesson and the Lessons list filters by them on large sites.
				foreach ( $lesson_taxonomies as $lrn_tax ) :
					$lrn_tax_terms = $taxonomy_terms[ $lrn_tax['type'] ] ?? array();
					?>
					<div class="lrn-admin-card">
						<h3 class="lrn-admin-card__title">
							<i data-lucide="<?php echo esc_attr( $lrn_tax['icon'] ); ?>" class="lrn-icon--sm"></i>
							<?php echo esc_html( $lrn_tax['label'] ); ?>
						</h3>
						<div class="lrn-form-field">
							<?php if ( empty( $lrn_tax_terms ) ) : ?>
								<p class="lrn-form-help">
									<?php
									echo wp_kses_post(
										\Learnomy\Admin\UX::taxonomy_empty_help(
											(string) $lrn_tax['label'],
											admin_url( 'admin.php?page=learnomy-categories' )
										)
									);
									?>
								</p>
							<?php else : ?>
								<ul class="lrn-tag-checklist">
									<?php foreach ( $lrn_tax_terms as $lrn_term ) : ?>
										<li>
											<label class="lrn-checkbox-label">
												<input type="checkbox" name="tag_ids[]" value="<?php echo absint( $lrn_term->id ); ?>"
													<?php checked( in_array( (int) $lrn_term->id, $lesson_tag_ids, true ) ); ?>>
												<span><?php echo esc_html( $lrn_term->name ); ?></span>
											</label>
										</li>
									<?php endforeach; ?>
								</ul>
								<?php if ( ! empty( $lrn_tax['help'] ) ) : ?>
									<p class="lrn-form-help"><?php echo esc_html( $lrn_tax['help'] ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( current_user_can( 'manage_options' ) ) : ?>
								<div class="lrn-tag-quick-add" data-tax-type="<?php echo esc_attr( $lrn_tax['type'] ); ?>">
									<input type="text" class="lrn-tag-quick-add__input regular-text" placeholder="<?php esc_attr_e( 'New tag name…', 'learnomy' ); ?>">
									<button type="button" class="lrn-admin-btn lrn-admin-btn--sm lrn-tag-quick-add__btn">
										<i data-lucide="plus" aria-hidden="true"></i> <?php esc_html_e( 'Add Tag', 'learnomy' ); ?>
									</button>
									<span class="lrn-tag-quick-add__status" aria-live="polite"></span>
								</div>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

		</div><!-- /.lrn-course-form__sidebar -->

	</div><!-- /.lrn-course-form__layout -->
</div><!-- /.learnomy-admin -->
