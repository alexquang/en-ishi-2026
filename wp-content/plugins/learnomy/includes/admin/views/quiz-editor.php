<?php
/**
 * Admin quiz editor view — Phase 2 premium UX.
 *
 * Five collapsible `.lrn-settings-section` accordions — Basic (which carries
 * the identity Title + URL slug), Grading, Behaviour, Access & Schedule, and
 * Attempts & Cooldown — sit inside a single Quiz Details `.lrn-admin-card`. A
 * second card hosts the
 * Questions slot list. The layout mirrors `course-form.php` exactly so the
 * course and quiz editors feel like parts of the same product.
 *
 * The view defends itself against missing locals because the render method
 * in `class-admin.php` only passes `$quiz`, `$is_edit`, `$courses`. Anything
 * else — sections, slots, breadcrumb context — we resolve through models
 * here so the view stays functional if the render method is ever audited.
 *
 * @package Learnomy
 * @var object|null $quiz    Existing quiz row (null for a new quiz).
 * @var bool        $is_edit Whether we are editing an existing quiz.
 * @var object[]    $courses All courses the current user can manage.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Section;
use Learnomy\Models\Lesson;
use Learnomy\Models\QuizSlot;
use Learnomy\Models\Question;

// ── Resolve runtime locals the view needs ────────────────────────────────
// The render method in class-admin.php only passes the three @var fields
// above; everything below is resolved here so the view stays self-sufficient.

$quiz    = $quiz ?? null;
$is_edit = $is_edit ?? false;
$courses = $courses ?? array();

$current_course_id  = $is_edit ? (int) ( $quiz->course_id ?? 0 ) : 0;
$current_section_id = $is_edit ? (int) ( $quiz->section_id ?? 0 ) : 0;
$current_lesson_id  = $is_edit ? (int) ( $quiz->lesson_id ?? 0 ) : 0;

$course  = $current_course_id ? Course::find( $current_course_id ) : null;
$section = $current_section_id ? Section::find( $current_section_id ) : null;
$lesson  = $current_lesson_id ? Lesson::find( $current_lesson_id ) : null;

$sections = $current_course_id ? Section::get_for_course( $current_course_id ) : array();
// Section lessons — filter to top-level (no topic children) so the lesson
// dropdown isn't polluted by every topic in the course.
$lesson_choices = array();
if ( $current_section_id ) {
	foreach ( Lesson::get_for_section( $current_section_id, 0, 0, null ) as $candidate ) { // Editor picker: every status.
		if ( 0 === (int) ( $candidate->parent_id ?? 0 ) ) {
			$lesson_choices[] = $candidate;
		}
	}
}

$slots             = ( $is_edit && $quiz ) ? QuizSlot::get_for_quiz( (int) $quiz->id ) : array();
$slots_total_marks = 0.0;

// Hydrate slot rows with question title + type so the template can render
// without a second round of model calls per slot. Batch-load the questions in
// ONE query (was a Question::find per slot — N queries to open a quiz editor).
$slot_questions = Question::find_in( array_map( static fn( $s ) => (int) $s->question_id, $slots ) );
foreach ( $slots as $slot ) {
	$slots_total_marks   += (float) $slot->marks;
	$slot_question        = $slot_questions[ (int) $slot->question_id ] ?? null;
	$slot->question_title = $slot_question ? ( $slot_question->title ?? '' ) : '';
	$slot->question_type  = $slot_question ? ( $slot_question->type ?? '' ) : '';
}

// ── Enum label maps ──────────────────────────────────────────────────────

$quiz_modes = array(
	'graded'   => __( 'Graded', 'learnomy' ),
	'practice' => __( 'Practice', 'learnomy' ),
	'survey'   => __( 'Survey', 'learnomy' ),
);

// Canonical `latest` is the only forward value — `last` is accepted on read
// by the service layer but never offered to instructors here.
$grading_methods = array(
	'highest' => __( 'Highest', 'learnomy' ),
	'first'   => __( 'First', 'learnomy' ),
	'latest'  => __( 'Latest', 'learnomy' ),
	'average' => __( 'Average', 'learnomy' ),
);

$navigation_modes = array(
	'free'       => __( 'Free (students can jump between questions)', 'learnomy' ),
	'sequential' => __( 'Sequential (one at a time, no going back)', 'learnomy' ),
);

$feedback_timings = array(
	'after_submit'   => __( 'After submit (feedback after the whole quiz)', 'learnomy' ),
	'after_question' => __( 'After each question', 'learnomy' ),
	'immediate'      => __( 'Immediate (as soon as the answer is chosen)', 'learnomy' ),
	'after_attempt'  => __( 'After attempt finalizes (includes manual grading)', 'learnomy' ),
	'after_pass'     => __( 'Only after passing (full review for those who pass, score only otherwise)', 'learnomy' ),
	'never'          => __( 'Never (keep answers hidden)', 'learnomy' ),
);

$status_choices = array(
	'draft'     => __( 'Draft (hidden from students)', 'learnomy' ),
	'published' => __( 'Published', 'learnomy' ),
	'archived'  => __( 'Archived', 'learnomy' ),
);

$display_templates = array(
	'focused'        => array(
		'label' => __( 'Focused', 'learnomy' ),
		'desc'  => __( 'One at a time, resume anywhere. Best for timed and high-stakes exams.', 'learnomy' ),
		'icon'  => 'focus',
		'pro'   => false,
	),
	'classic'        => array(
		'label' => __( 'Classic', 'learnomy' ),
		'desc'  => __( 'All questions on one page, submit once. Best for short class quizzes and homework.', 'learnomy' ),
		'icon'  => 'list',
		'pro'   => false,
	),
	'sectioned'      => array(
		'label' => __( 'Sectioned', 'learnomy' ),
		'desc'  => __( 'Questions split into parts (free or fixed order). Best for SAT/GRE-style and mock exams.', 'learnomy' ),
		'icon'  => 'layers',
		'pro'   => true,
	),
	'conversational' => array(
		'label' => __( 'Conversational', 'learnomy' ),
		'desc'  => __( 'One at a time, keyboard-friendly. Best for casual knowledge checks.', 'learnomy' ),
		'icon'  => 'message-circle',
		'pro'   => true,
	),
	'review-board'   => array(
		'label' => __( 'Review board', 'learnomy' ),
		'desc'  => __( 'Jump to any question in one click. Best for long certification and entrance exams.', 'learnomy' ),
		'icon'  => 'layout-grid',
		'pro'   => true,
	),
);

// ── Current values ───────────────────────────────────────────────────────

$page_title = $is_edit
	/* translators: %s: quiz title */
	? sprintf( __( 'Edit Quiz: %s', 'learnomy' ), $quiz->title ?? '' )
	: __( 'Add New Quiz', 'learnomy' );

$ajax_action = $is_edit ? 'learnomy_update_quiz' : 'learnomy_create_quiz';

$current_mode    = $is_edit ? (string) ( $quiz->mode ?? 'graded' ) : 'graded';
$current_pass    = $is_edit ? (int) ( $quiz->passing_score ?? 70 ) : 70;
$current_grading = $is_edit ? (string) ( $quiz->grading_method ?? 'highest' ) : 'highest';
// Normalize legacy `last` so the select round-trips cleanly.
if ( 'last' === $current_grading ) {
	$current_grading = 'latest';
}
// The field uses "0 = unlimited"; the store keeps unlimited as NULL. On EDIT,
// show the saved value with NULL -> 0, so the unlimited option round-trips
// (the `?? 1` here turned a saved "unlimited" back into "1" every reopen —
// the field lied about what was saved). A NEW quiz still defaults to 1.
$current_attempts = $is_edit ? (int) $quiz->attempt_limit : 1;

$current_shuffle_q      = $is_edit ? (int) ( $quiz->shuffle_questions ?? 0 ) : 0;
$current_shuffle_a      = $is_edit ? (int) ( $quiz->shuffle_answers ?? 0 ) : 0;
$current_group_category = $is_edit ? (int) ( $quiz->group_by_category ?? 0 ) : 0;
$current_navigation     = $is_edit ? (string) ( $quiz->navigation ?? 'free' ) : 'free';
$current_section_nav    = $is_edit ? (string) ( $quiz->section_navigation ?? 'linear' ) : 'linear';
$current_qps            = $is_edit ? max( 1, (int) ( $quiz->questions_per_section ?? 10 ) ) : 10;
$current_feedback       = $is_edit ? (string) ( $quiz->feedback_timing ?? 'after_submit' ) : 'after_submit';
$current_template       = $is_edit ? (string) ( $quiz->display_template ?? 'focused' ) : 'focused';

$current_access_code = $is_edit ? (string) ( $quiz->access_code ?? '' ) : '';
$current_open_at     = $is_edit ? (string) ( $quiz->open_at ?? '' ) : '';
$current_close_at    = $is_edit ? (string) ( $quiz->close_at ?? '' ) : '';
$current_status      = $is_edit ? (string) ( $quiz->status ?? 'draft' ) : 'draft';

// datetime-local expects "YYYY-MM-DDTHH:MM" — DB stores "YYYY-MM-DD HH:MM:SS".
$current_cooldown_seconds = $is_edit ? (int) ( $quiz->attempt_cooldown_seconds ?? 0 ) : 0;
$current_cooldown_minutes = (int) floor( $current_cooldown_seconds / 60 );
$current_max_failed       = $is_edit ? (int) ( $quiz->max_failed_attempts ?? 0 ) : 0;
$current_slug             = $is_edit ? (string) ( $quiz->slug ?? '' ) : '';

$time_limit_sec = $is_edit ? (int) ( $quiz->time_limit_seconds ?? 0 ) : 0;
$time_mins      = intdiv( $time_limit_sec, 60 );
$time_secs      = $time_limit_sec % 60;
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="help-circle" class="lrn-page-header__icon"></i>
				<?php echo esc_html( $page_title ); ?>
			</h1>
			<?php if ( $is_edit ) : ?>
				<nav class="lrn-breadcrumb" aria-label="<?php esc_attr_e( 'Quiz context', 'learnomy' ); ?>">
					<?php if ( $course ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-edit-course&course_id=' . absint( $course->id ) ) ); ?>" class="lrn-breadcrumb__item">
							<?php echo esc_html( $course->title ); ?>
						</a>
						<?php if ( $section ) : ?>
							<span class="lrn-breadcrumb__sep" aria-hidden="true">/</span>
							<span class="lrn-breadcrumb__item"><?php echo esc_html( $section->title ); ?></span>
						<?php endif; ?>
						<?php if ( $lesson ) : ?>
							<span class="lrn-breadcrumb__sep" aria-hidden="true">/</span>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-lesson-editor&lesson_id=' . absint( $lesson->id ) ) ); ?>" class="lrn-breadcrumb__item">
								<?php echo esc_html( $lesson->title ); ?>
							</a>
						<?php endif; ?>
					<?php else : ?>
						<span class="lrn-breadcrumb__item lrn-muted"><?php esc_html_e( 'Standalone quiz', 'learnomy' ); ?></span>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		</div>
		<div class="lrn-page-header__actions">
			<?php
			if ( $is_edit && $quiz ) {
				/**
				 * AI authoring slot — the Pro ai-authoring extension renders its
				 * "Generate questions with AI" trigger here so admins can AI-author
				 * quiz questions on the backend. No-op when AI is off / not connected.
				 *
				 * @param int    $quiz_id The quiz being edited.
				 * @param string $title   The quiz title (seeds the AI topic).
				 */
				do_action( 'learnomy_pro_quiz_editor_ai_slot', (int) $quiz->id, (string) ( $quiz->title ?? '' ) );
			}
			?>
			<?php if ( ! empty( $quiz_front_url ) ) : ?>
				<a href="<?php echo esc_url( $quiz_front_url ); ?>" class="lrn-admin-btn" target="_blank" rel="noopener">
					<i data-lucide="eye" aria-hidden="true"></i> <?php esc_html_e( 'View Quiz', 'learnomy' ); ?>
				</a>
			<?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-quizzes' ) ); ?>" class="lrn-admin-btn">
				<i data-lucide="arrow-left"></i> <?php esc_html_e( 'Back to Quizzes', 'learnomy' ); ?>
			</a>
			<?php if ( $is_edit ) : ?>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--danger" id="lrn-delete-quiz"
						data-quiz-id="<?php echo absint( $quiz->id ); ?>">
					<i data-lucide="trash-2" aria-hidden="true"></i> <?php esc_html_e( 'Delete Quiz', 'learnomy' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<form data-ajax-action="<?php echo esc_attr( $ajax_action ); ?>" class="lrn-editor-form lrn-quiz-editor"
		data-quiz-id="<?php echo $is_edit ? absint( $quiz->id ) : 0; ?>"
		data-course-id="<?php echo absint( $current_course_id ); ?>"
		data-section-id="<?php echo absint( $current_section_id ); ?>"
		data-lesson-id="<?php echo absint( $current_lesson_id ); ?>">
		<?php if ( $is_edit ) : ?>
			<input type="hidden" name="quiz_id" value="<?php echo absint( $quiz->id ); ?>">
		<?php endif; ?>

		<?php if ( $is_edit ) : ?>
		<div class="lrn-course-form__layout">
		<div class="lrn-course-form__main">
		<?php endif; ?>

		<!-- ── Quiz Details card (holds every settings section) ───────── -->
		<div class="lrn-admin-card">
			<h3 class="lrn-admin-card__title">
				<i data-lucide="help-circle" class="lrn-icon--sm"></i>
				<?php esc_html_e( 'Quiz Details', 'learnomy' ); ?>
			</h3>
			<p class="lrn-admin-card__desc">
				<?php esc_html_e( 'Everything students see: basic info, grading, timing, behaviour, access, and attempts.', 'learnomy' ); ?>
			</p>

			<div class="lrn-quiz-editor__settings">

				<!-- ── Basic ────────────────────────────────────────── -->
				<section class="lrn-settings-section is-open" data-section="basic">
					<button type="button" class="lrn-settings-section__header" aria-expanded="true">
						<span class="lrn-settings-section__heading">
							<i data-lucide="settings"></i>
							<span><?php esc_html_e( 'Basic', 'learnomy' ); ?></span>
						</span>
						<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
					</button>
					<div class="lrn-settings-section__body">
						<p class="lrn-settings-section__help">
							<?php esc_html_e( 'Name the quiz, write a short description, and decide where it lives.', 'learnomy' ); ?>
						</p>

						<div class="lrn-form-field lrn-form-field--wide">
							<label for="lrn-quiz-title">
								<i data-lucide="type" class="lrn-icon--sm"></i>
								<?php esc_html_e( 'Quiz Title', 'learnomy' ); ?>
								<span class="required" aria-hidden="true">*</span>
							</label>
							<input type="text" id="lrn-quiz-title" name="title"
								value="<?php echo esc_attr( $quiz->title ?? '' ); ?>"
								class="large-text lrn-input--lg" required>
						</div>

						<div class="lrn-form-field lrn-form-field--wide">
							<label for="lrn-quiz-slug">
								<i data-lucide="link" class="lrn-icon--sm"></i>
								<?php esc_html_e( 'Quiz URL slug', 'learnomy' ); ?>
							</label>
							<input type="text" id="lrn-quiz-slug" name="slug"
								value="<?php echo esc_attr( $current_slug ); ?>"
								class="regular-text" pattern="[a-z0-9\-]*">
							<?php if ( ! empty( $quiz_url_pattern ) ) : ?>
								<p class="lrn-form-help">
									<?php
									printf(
										/* translators: %s: the quiz URL pattern, e.g. /quiz/<slug>/ */
										esc_html__( 'Front-end URL: %s. Leave blank to generate it from the title.', 'learnomy' ),
										esc_html( $quiz_url_pattern )
									);
									?>
								</p>
							<?php endif; ?>
						</div>

						<div class="lrn-form-field lrn-form-field--wide">
							<label for="lrn-quiz-desc-editor"><?php esc_html_e( 'Description', 'learnomy' ); ?></label>
							<?php
							// Editor.js rich field. The host is mounted by quiz-editor.js,
							// which seeds from + serializes back to the hidden input below.
							// The hidden input keeps name="description" so the existing
							// save path (actions.js → REST) persists the block JSON
							// unchanged. render_editorjs_content() handles both JSON and
							// legacy plain/HTML on the front, so old quizzes keep working.
							?>
							<div id="lrn-quiz-desc-editor" class="lrn-editor-host"
								data-placeholder="<?php esc_attr_e( 'Explain what this quiz covers and what students need to pass it.', 'learnomy' ); ?>"></div>
							<input type="hidden" id="lrn-quiz-description" name="description"
								value="<?php echo esc_attr( \Learnomy\Content\Content_HTML::for_editor( (string) ( $quiz->description ?? '' ) ) ); ?>">
						</div>

						<div class="lrn-form-field">
							<label for="lrn-quiz-mode"><?php esc_html_e( 'Mode', 'learnomy' ); ?></label>
							<select id="lrn-quiz-mode" name="mode">
								<?php foreach ( $quiz_modes as $mode_key => $mode_label ) : ?>
									<option value="<?php echo esc_attr( $mode_key ); ?>" <?php selected( $current_mode, $mode_key ); ?>>
										<?php echo esc_html( $mode_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="lrn-form-help"><?php esc_html_e( 'Graded counts toward completion, practice doesn\'t, survey collects answers without grading.', 'learnomy' ); ?></p>
						</div>

						<?php
						/*
						 * Course / Section / Lesson are three chained selects that
						 * together decide WHERE a quiz renders for students, and
						 * nothing said so. Each one is disabled until its parent is
						 * chosen, so an author who had not picked a section met a
						 * greyed-out Lesson field with one option and no explanation
						 * — and concluded lesson quizzes did not exist (Basecamp
						 * 10184407612). Naming the three outcomes once, here, costs
						 * a paragraph and removes the guesswork.
						 */
						?>
						<?php
						// Every noun through its label helper, so a site that renamed
						// Course to Program and Lesson to Module reads its own words
						// here. The Labels ratchet enforces it, and it is right to:
						// this is the one place that explains the placement model, so
						// it is the worst place to speak a vocabulary the owner does
						// not use.
						$lrn_l_course  = \Learnomy\course_label();
						$lrn_l_section = \Learnomy\section_label();
						$lrn_l_lesson  = \Learnomy\lesson_label();
						?>
						<p class="lrn-form-help lrn-form-help--group">
							<?php
							printf(
								/* translators: %s: the Quiz entity label, e.g. "quiz". */
								esc_html__( 'Where this %s appears for students:', 'learnomy' ),
								esc_html( strtolower( \Learnomy\quiz_label() ) )
							);
							?>
							<br>
							<?php
							printf(
								/* translators: 1: Course label, 2: Section label. */
								esc_html__( 'Whole %1$s (a final exam) - pick a %1$s, leave %2$s empty.', 'learnomy' ),
								esc_html( strtolower( $lrn_l_course ) ),
								esc_html( $lrn_l_section )
							);
							?>
							<br>
							<?php
							printf(
								/* translators: 1: Section label, 2: Course label, 3: Lesson label. */
								esc_html__( 'End of a %1$s - pick a %2$s and a %1$s, leave %3$s empty.', 'learnomy' ),
								esc_html( strtolower( $lrn_l_section ) ),
								esc_html( strtolower( $lrn_l_course ) ),
								esc_html( $lrn_l_lesson )
							);
							?>
							<br>
							<?php
							printf(
								/* translators: 1: Lesson label, 2: Course label, 3: Section label. */
								esc_html__( 'After one %1$s - pick a %2$s, a %3$s, then that %1$s.', 'learnomy' ),
								esc_html( strtolower( $lrn_l_lesson ) ),
								esc_html( strtolower( $lrn_l_course ) ),
								esc_html( strtolower( $lrn_l_section ) )
							);
							?>
						</p>

						<div class="lrn-form-field">
							<label for="lrn-quiz-course"><?php esc_html_e( 'Course', 'learnomy' ); ?></label>
							<select id="lrn-quiz-course" name="course_id">
								<option value=""><?php esc_html_e( 'Standalone', 'learnomy' ); ?></option>
								<?php foreach ( $courses as $c ) : ?>
									<option value="<?php echo absint( $c->id ); ?>" <?php selected( $current_course_id, (int) $c->id ); ?>>
										<?php echo esc_html( $c->title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="lrn-form-help"><?php esc_html_e( 'Leave empty for a standalone quiz.', 'learnomy' ); ?></p>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-quiz-section"><?php esc_html_e( 'Section', 'learnomy' ); ?></label>
							<select id="lrn-quiz-section" name="section_id" <?php echo $current_course_id ? '' : 'disabled'; ?>>
								<option value=""><?php esc_html_e( 'Select a section', 'learnomy' ); ?></option>
								<?php foreach ( $sections as $s ) : ?>
									<option value="<?php echo absint( $s->id ); ?>" <?php selected( $current_section_id, (int) $s->id ); ?>>
										<?php echo esc_html( $s->title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php // A disabled control has to say what would enable it. ?>
							<p class="lrn-form-help">
								<?php
								if ( $current_course_id ) {
									printf(
										/* translators: 1: Quiz label, 2: Course label. */
										esc_html__( 'Leave empty to place the %1$s at the end of the whole %2$s.', 'learnomy' ),
										esc_html( strtolower( \Learnomy\quiz_label() ) ),
										esc_html( strtolower( $lrn_l_course ) )
									);
								} else {
									printf(
										/* translators: %s: Course label. */
										esc_html__( 'Pick a %s first.', 'learnomy' ),
										esc_html( strtolower( $lrn_l_course ) )
									);
								}
								?>
							</p>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-quiz-lesson"><?php esc_html_e( 'Lesson', 'learnomy' ); ?></label>
							<select id="lrn-quiz-lesson" name="lesson_id" <?php echo $current_section_id ? '' : 'disabled'; ?>>
								<option value=""><?php esc_html_e( 'End of section (no lesson)', 'learnomy' ); ?></option>
								<?php foreach ( $lesson_choices as $l ) : ?>
									<option value="<?php echo absint( $l->id ); ?>" <?php selected( $current_lesson_id, (int) $l->id ); ?>>
										<?php echo esc_html( $l->title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="lrn-form-help">
								<?php
								// Was "Binds the quiz to a specific lesson in the player
								// flow" in both states, which told an author staring at a
								// greyed-out control nothing about how to un-grey it.
								if ( $current_section_id ) {
									printf(
										/* translators: 1: Quiz label, 2: Lesson label, 3: Section label. */
										esc_html__( 'The %1$s appears under this %2$s. Leave empty to place it at the end of the %3$s.', 'learnomy' ),
										esc_html( strtolower( \Learnomy\quiz_label() ) ),
										esc_html( strtolower( $lrn_l_lesson ) ),
										esc_html( strtolower( $lrn_l_section ) )
									);
								} else {
									printf(
										/* translators: 1: Section label, 2: plural Lesson label. */
										esc_html__( 'Pick a %1$s first, then choose one of its %2$s.', 'learnomy' ),
										esc_html( strtolower( $lrn_l_section ) ),
										esc_html( strtolower( \Learnomy\lesson_label( true ) ) )
									);
								}
								?>
							</p>
						</div>
					</div>
				</section>

				<!-- ── Grading ──────────────────────────────────────── -->
				<section class="lrn-settings-section is-open" data-section="grading">
					<button type="button" class="lrn-settings-section__header" aria-expanded="true">
						<span class="lrn-settings-section__heading">
							<i data-lucide="award"></i>
							<span><?php esc_html_e( 'Grading', 'learnomy' ); ?></span>
						</span>
						<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
					</button>
					<div class="lrn-settings-section__body">
						<p class="lrn-settings-section__help">
							<?php esc_html_e( 'How this quiz is scored: passing threshold, time limit, attempt limit, and grading method.', 'learnomy' ); ?>
						</p>

						<div class="lrn-form-field">
							<label for="lrn-quiz-passing"><?php esc_html_e( 'Passing score', 'learnomy' ); ?></label>
							<div class="lrn-range-row">
								<input type="range" id="lrn-quiz-passing" name="passing_score"
									min="0" max="100" step="1"
									value="<?php echo esc_attr( (string) $current_pass ); ?>"
									data-lrn-range-output="passing-score-output">
								<output class="lrn-range-row__value" id="passing-score-output" for="lrn-quiz-passing">
									<?php echo esc_html( $current_pass . '%' ); ?>
								</output>
							</div>
							<p class="lrn-form-help"><?php esc_html_e( 'Minimum score (0-100%) required to pass.', 'learnomy' ); ?></p>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-quiz-time-limit-mins"><?php esc_html_e( 'Time limit', 'learnomy' ); ?></label>
							<div class="lrn-duration-input">
								<input type="number" id="lrn-quiz-time-limit-mins" class="small-text" min="0" step="1"
									value="<?php echo esc_attr( (string) $time_mins ); ?>">
								<span class="lrn-duration-input__unit"><?php esc_html_e( 'min', 'learnomy' ); ?></span>
								<input type="number" id="lrn-quiz-time-limit-secs" class="small-text" min="0" max="59" step="1"
									value="<?php echo esc_attr( (string) $time_secs ); ?>">
								<span class="lrn-duration-input__unit"><?php esc_html_e( 'sec', 'learnomy' ); ?></span>
							</div>
							<input type="hidden" id="lrn-quiz-time-limit-seconds" name="time_limit_seconds" value="<?php echo esc_attr( (string) $time_limit_sec ); ?>">
							<p class="lrn-form-help"><?php esc_html_e( 'Saved as whole seconds. Leave 0 for no time limit.', 'learnomy' ); ?></p>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-quiz-attempts"><?php esc_html_e( 'Attempt limit', 'learnomy' ); ?></label>
							<input type="number" id="lrn-quiz-attempts" name="attempt_limit"
								value="<?php echo esc_attr( (string) $current_attempts ); ?>"
								class="small-text" min="0">
							<p class="lrn-form-help"><?php esc_html_e( '0 = unlimited. Default 1.', 'learnomy' ); ?></p>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-quiz-grading-method"><?php esc_html_e( 'Grading method', 'learnomy' ); ?></label>
							<select id="lrn-quiz-grading-method" name="grading_method">
								<?php foreach ( $grading_methods as $gk => $gl ) : ?>
									<option value="<?php echo esc_attr( $gk ); ?>" <?php selected( $current_grading, $gk ); ?>>
										<?php echo esc_html( $gl ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="lrn-form-help"><?php esc_html_e( 'Which attempt counts when a student has multiple.', 'learnomy' ); ?></p>
						</div>
					</div>
				</section>

				<!-- ── Behaviour ────────────────────────────────────── -->
				<section class="lrn-settings-section" data-section="behaviour">
					<button type="button" class="lrn-settings-section__header" aria-expanded="false">
						<span class="lrn-settings-section__heading">
							<i data-lucide="sliders"></i>
							<span><?php esc_html_e( 'Behaviour', 'learnomy' ); ?></span>
						</span>
						<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
					</button>
					<div class="lrn-settings-section__body">
						<p class="lrn-settings-section__help">
							<?php esc_html_e( 'How the quiz feels to take: shuffling, navigation, feedback, and layout.', 'learnomy' ); ?>
						</p>

						<div class="lrn-form-field">
							<label class="lrn-checkbox-label" for="lrn-quiz-shuffle-q">
								<input type="hidden" name="shuffle_questions" value="0">
								<input type="checkbox" id="lrn-quiz-shuffle-q" name="shuffle_questions" value="1" <?php checked( $current_shuffle_q, 1 ); ?>>
								<?php esc_html_e( 'Shuffle questions', 'learnomy' ); ?>
							</label>
							<p class="lrn-form-help"><?php esc_html_e( 'Randomise question order per attempt.', 'learnomy' ); ?></p>
						</div>

						<div class="lrn-form-field">
							<label class="lrn-checkbox-label" for="lrn-quiz-shuffle-a">
								<input type="hidden" name="shuffle_answers" value="0">
								<input type="checkbox" id="lrn-quiz-shuffle-a" name="shuffle_answers" value="1" <?php checked( $current_shuffle_a, 1 ); ?>>
								<?php esc_html_e( 'Shuffle answers', 'learnomy' ); ?>
							</label>
							<p class="lrn-form-help"><?php esc_html_e( 'Randomise answer choice order', 'learnomy' ); ?></p>
						</div>

						<div class="lrn-form-field">
							<label class="lrn-checkbox-label" for="lrn-quiz-group-category">
								<input type="hidden" name="group_by_category" value="0">
								<input type="checkbox" id="lrn-quiz-group-category" name="group_by_category" value="1" <?php checked( $current_group_category, 1 ); ?>>
								<?php esc_html_e( 'Group questions by category', 'learnomy' ); ?>
							</label>
							<p class="lrn-form-help"><?php esc_html_e( 'Show questions under their category heading, and a per-category score on the results screen.', 'learnomy' ); ?></p>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-quiz-navigation"><?php esc_html_e( 'Navigation', 'learnomy' ); ?></label>
							<select id="lrn-quiz-navigation" name="navigation">
								<?php foreach ( $navigation_modes as $nav_key => $nav_label ) : ?>
									<option value="<?php echo esc_attr( $nav_key ); ?>" <?php selected( $current_navigation, $nav_key ); ?>>
										<?php echo esc_html( $nav_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-quiz-section-navigation"><?php esc_html_e( 'Section navigation', 'learnomy' ); ?></label>
							<select id="lrn-quiz-section-navigation" name="section_navigation">
								<option value="linear" <?php selected( $current_section_nav, 'linear' ); ?>><?php esc_html_e( 'Linear - complete each section in order', 'learnomy' ); ?></option>
								<option value="free" <?php selected( $current_section_nav, 'free' ); ?>><?php esc_html_e( 'Free - candidate picks section order', 'learnomy' ); ?></option>
							</select>
							<p class="lrn-form-help"><?php esc_html_e( 'Applies to the Sectioned display template. Free lets the candidate choose which section to attempt first and switch between them until submit.', 'learnomy' ); ?></p>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-quiz-questions-per-section"><?php esc_html_e( 'Questions per section', 'learnomy' ); ?></label>
							<input type="number" id="lrn-quiz-questions-per-section" name="questions_per_section"
								min="1" max="100" class="small-text"
								value="<?php echo esc_attr( (string) $current_qps ); ?>">
							<p class="lrn-form-help"><?php esc_html_e( 'Applies to the Sectioned display template: how many questions each auto-built section holds.', 'learnomy' ); ?></p>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-quiz-feedback-timing"><?php esc_html_e( 'Feedback timing', 'learnomy' ); ?></label>
							<select id="lrn-quiz-feedback-timing" name="feedback_timing">
								<?php foreach ( $feedback_timings as $ft_key => $ft_label ) : ?>
									<option value="<?php echo esc_attr( $ft_key ); ?>" <?php selected( $current_feedback, $ft_key ); ?>>
										<?php echo esc_html( $ft_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="lrn-form-field lrn-form-field--wide">
							<label><?php esc_html_e( 'Display template', 'learnomy' ); ?></label>
							<div class="lrn-template-picker" role="radiogroup" aria-label="<?php esc_attr_e( 'Quiz display template', 'learnomy' ); ?>">
								<?php
								$lrn_is_pro = apply_filters( 'learnomy_is_pro', false );
								foreach ( $display_templates as $tpl_key => $tpl ) :
									$tpl_locked = ! empty( $tpl['pro'] ) && ! $lrn_is_pro;
									?>
									<label class="lrn-template-card<?php echo $current_template === $tpl_key ? ' is-active' : ''; ?><?php echo $tpl_locked ? ' is-locked' : ''; ?>"
										<?php echo $tpl_locked ? 'title="' . esc_attr__( 'Upgrade to Pro to use this layout', 'learnomy' ) . '"' : ''; ?>>
										<input type="radio" name="display_template" value="<?php echo esc_attr( $tpl_key ); ?>"
											<?php checked( $current_template, $tpl_key ); ?>
											<?php disabled( $tpl_locked ); ?>>
										<span class="lrn-template-card__icon">
											<i data-lucide="<?php echo esc_attr( $tpl['icon'] ); ?>" aria-hidden="true"></i>
										</span>
										<span class="lrn-template-card__body">
											<span class="lrn-template-card__title">
												<?php echo esc_html( $tpl['label'] ); ?>
												<?php if ( ! empty( $tpl['pro'] ) ) : ?>
													<span class="lrn-template-card__pro"><?php esc_html_e( 'Pro', 'learnomy' ); ?></span>
												<?php endif; ?>
											</span>
											<span class="lrn-template-card__desc">
												<?php echo esc_html( $tpl['desc'] ); ?>
												<?php if ( $tpl_locked ) : ?>
													<span class="lrn-template-card__upgrade"><?php esc_html_e( 'Upgrade to Pro to use this layout', 'learnomy' ); ?></span>
												<?php endif; ?>
											</span>
										</span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				</section>

				<!-- ── Access & Schedule ───────────────────────────── -->
				<section class="lrn-settings-section" data-section="access">
					<button type="button" class="lrn-settings-section__header" aria-expanded="false">
						<span class="lrn-settings-section__heading">
							<i data-lucide="clock"></i>
							<span><?php esc_html_e( 'Access & Schedule', 'learnomy' ); ?></span>
						</span>
						<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
					</button>
					<div class="lrn-settings-section__body">
						<p class="lrn-settings-section__help">
							<?php esc_html_e( 'Gate the quiz with a code or a time window. All fields are optional.', 'learnomy' ); ?>
						</p>

						<div class="lrn-form-field">
							<label for="lrn-quiz-access-code"><?php esc_html_e( 'Access code', 'learnomy' ); ?></label>
							<input type="text" id="lrn-quiz-access-code" name="access_code"
								value="<?php echo esc_attr( $current_access_code ); ?>"
								class="regular-text" autocomplete="off" maxlength="50">
							<p class="lrn-form-help">
								<?php esc_html_e( 'Students must enter this code before starting. Leave blank for no gate.', 'learnomy' ); ?>
							</p>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-quiz-open-at"><?php esc_html_e( 'Opens at', 'learnomy' ); ?></label>
							<input type="datetime-local" id="lrn-quiz-open-at" name="open_at"
								value="<?php echo esc_attr( \Learnomy\datetime_local_input( $current_open_at ) ); ?>">
							<p class="lrn-form-help"><?php esc_html_e( 'In your site timezone. Before this moment students see the quiz but cannot start.', 'learnomy' ); ?></p>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-quiz-close-at"><?php esc_html_e( 'Closes at', 'learnomy' ); ?></label>
							<input type="datetime-local" id="lrn-quiz-close-at" name="close_at"
								value="<?php echo esc_attr( \Learnomy\datetime_local_input( $current_close_at ) ); ?>">
							<p class="lrn-form-help"><?php esc_html_e( 'In your site timezone. After this moment the quiz is closed to new attempts.', 'learnomy' ); ?></p>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-quiz-status"><?php esc_html_e( 'Status', 'learnomy' ); ?></label>
							<select id="lrn-quiz-status" name="status">
								<?php foreach ( $status_choices as $status_key => $status_label ) : ?>
									<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $current_status, $status_key ); ?>>
										<?php echo esc_html( $status_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</section>

				<!-- ── Attempts & Cooldown ─────────────────────────── -->
				<section class="lrn-settings-section" data-section="attempts">
					<button type="button" class="lrn-settings-section__header" aria-expanded="false">
						<span class="lrn-settings-section__heading">
							<i data-lucide="shield"></i>
							<span><?php esc_html_e( 'Attempts & Cooldown', 'learnomy' ); ?></span>
						</span>
						<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
					</button>
					<div class="lrn-settings-section__body">
						<p class="lrn-settings-section__help">
							<?php esc_html_e( 'How long students have to wait between attempts, and when they get locked out.', 'learnomy' ); ?>
						</p>

						<div class="lrn-form-field">
							<label for="lrn-quiz-cooldown-mins"><?php esc_html_e( 'Cooldown between attempts', 'learnomy' ); ?></label>
							<div class="lrn-duration-input">
								<input type="number" id="lrn-quiz-cooldown-mins" class="small-text" min="0" step="1"
									value="<?php echo esc_attr( (string) $current_cooldown_minutes ); ?>">
								<span class="lrn-duration-input__unit"><?php esc_html_e( 'min', 'learnomy' ); ?></span>
							</div>
							<input type="hidden" id="lrn-quiz-cooldown-seconds" name="attempt_cooldown_seconds"
								value="<?php echo esc_attr( (string) $current_cooldown_seconds ); ?>">
							<p class="lrn-form-help"><?php esc_html_e( 'Students must wait this long after submitting before starting again.', 'learnomy' ); ?></p>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-quiz-max-failed"><?php esc_html_e( 'Max failed attempts', 'learnomy' ); ?></label>
							<input type="number" id="lrn-quiz-max-failed" name="max_failed_attempts"
								value="<?php echo esc_attr( (string) $current_max_failed ); ?>"
								class="small-text" min="0" max="255">
							<p class="lrn-form-help"><?php esc_html_e( '0 = unlimited. Students who fail this many times are locked out until an instructor resets them.', 'learnomy' ); ?></p>
						</div>
					</div>
				</section>

			</div><!-- /.lrn-quiz-editor__settings -->

			<div class="lrn-quiz-editor__actions">
				<button type="submit" class="lrn-admin-btn lrn-admin-btn--primary">
					<i data-lucide="save"></i>
					<?php echo $is_edit ? esc_html__( 'Update Quiz', 'learnomy' ) : esc_html__( 'Create Quiz', 'learnomy' ); ?>
				</button>
			</div>
		</div>

		<?php if ( $is_edit ) : ?>
			<!-- ── Questions card ─────────────────────────────────── -->
			<div class="lrn-admin-card">
				<div class="lrn-quiz-editor__questions-head">
					<div>
						<h3 class="lrn-admin-card__title">
							<i data-lucide="list-checks" class="lrn-icon--sm"></i>
							<?php esc_html_e( 'Questions', 'learnomy' ); ?>
						</h3>
						<p class="lrn-admin-card__desc">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: question count, 2: total marks */
									__( '%1$d questions / %2$s marks. Drag to reorder. Click marks to edit.', 'learnomy' ),
									count( $slots ),
									number_format_i18n( (float) $slots_total_marks, 2 )
								)
							);
							?>
						</p>
					</div>
				</div>

				<div class="lrn-quiz-slots" id="lrn-quiz-slots" data-quiz-id="<?php echo absint( $quiz->id ); ?>">
					<?php if ( empty( $slots ) ) : ?>
						<div class="lrn-quiz-slots__empty">
							<i data-lucide="inbox" aria-hidden="true"></i>
							<p><?php esc_html_e( 'No questions attached yet. Add some from the question bank.', 'learnomy' ); ?></p>
						</div>
					<?php else : ?>
						<ol class="lrn-quiz-slot-list">
							<?php foreach ( $slots as $slot ) : ?>
								<li class="lrn-quiz-slot" data-slot-id="<?php echo absint( $slot->id ); ?>" data-question-id="<?php echo absint( $slot->question_id ); ?>" draggable="true">
									<span class="lrn-quiz-slot__drag-handle" aria-hidden="true" title="<?php esc_attr_e( 'Drag to reorder', 'learnomy' ); ?>">
										<i data-lucide="grip-vertical"></i>
									</span>
									<span class="lrn-quiz-slot__main">
										<span class="lrn-quiz-slot__title"><?php echo esc_html( $slot->question_title ?? '' ); ?></span>
										<?php if ( ! empty( $slot->question_type ) ) : ?>
											<span class="lrn-quiz-slot__type"><?php echo esc_html( $slot->question_type ); ?></span>
										<?php endif; ?>
									</span>
									<label class="lrn-quiz-slot__marks">
										<span class="lrn-quiz-slot__marks-label"><?php esc_html_e( 'Marks', 'learnomy' ); ?></span>
										<input type="number" class="lrn-slot-marks small-text"
											name="slot_marks[<?php echo absint( $slot->id ); ?>]"
											data-slot-id="<?php echo absint( $slot->id ); ?>"
											min="0" max="999.99" step="0.1"
											value="<?php echo esc_attr( (string) $slot->marks ); ?>">
										<span class="lrn-slot-marks__status" aria-live="polite"></span>
									</label>
									<button type="button" class="lrn-admin-btn lrn-admin-btn--sm lrn-slot-remove" aria-label="<?php esc_attr_e( 'Remove question', 'learnomy' ); ?>">
										<i data-lucide="trash-2"></i>
									</button>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php endif; ?>
				</div>

				<div class="lrn-quiz-picker-actions">
					<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-open-question-picker">
						<i data-lucide="plus"></i> <?php esc_html_e( 'Add questions from bank', 'learnomy' ); ?>
					</button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-question-editor' ) ); ?>" class="lrn-admin-btn" target="_blank" rel="noopener">
						<i data-lucide="external-link"></i> <?php esc_html_e( 'Create new question', 'learnomy' ); ?>
					</a>
				</div>
			</div>

			<?php
			/**
			 * Pro seam — advanced authoring for a saved quiz.
			 *
			 * Pro's advanced-quizzes extension renders its Question Pool / per-slot
			 * Timer / Conditional-logic / Rubric panel here, so the SAME REST-driven
			 * control that the front-end builder uses is also reachable from wp-admin
			 * (the three-entry-points rule — frontend + backend + API stay in lockstep
			 * off one component). No-op without Pro. Fires only for a saved quiz so the
			 * panel always has a real id to bind to.
			 *
			 * @param int $quiz_id Saved quiz ID.
			 */
			do_action( 'learnomy_quiz_editor_advanced_panel', (int) $quiz->id );

			/**
			 * Renders extra quiz-editor fields so an integration can add its own
			 * panel/widget without forking this template (e.g. WP Fusion
			 * "apply tags / required tags" for this quiz). Uniform with
			 * learnomy_course_editor_fields / learnomy_lesson_editor_fields;
			 * persist on the matching `learnomy_quiz_saved` action.
			 *
			 * @param object|null $quiz The quiz being edited (null on create).
			 */
			do_action( 'learnomy_quiz_editor_fields', $quiz ?? null );
			?>

			</div><!-- /.lrn-course-form__main -->

			<div class="lrn-course-form__sidebar">
			<!-- ── Tags card ──────────────────────────────────────────── -->
			<?php foreach ( $quiz_taxonomies as $qz_tax ) : ?>
				<?php $qz_tax_terms = $taxonomy_terms[ $qz_tax['type'] ] ?? array(); ?>
				<div class="lrn-admin-card">
					<h3 class="lrn-admin-card__title">
						<i data-lucide="<?php echo esc_attr( $qz_tax['icon'] ); ?>" class="lrn-icon--sm"></i>
						<?php echo esc_html( $qz_tax['label'] ); ?>
					</h3>
					<div class="lrn-form-field">
						<?php
						// Empty sentinel so `tag_ids[]` is always submitted even
						// when every checkbox is unchecked — otherwise the browser
						// omits the field entirely and the controller can't tell
						// "no tags" from "field not sent", so the last tag could
						// never be removed. sync_object_tags() drops the empty
						// (intval 0) value, leaving a clean empty set.
						?>
						<input type="hidden" name="tag_ids[]" value="">
						<?php if ( empty( $qz_tax_terms ) ) : ?>
							<p class="lrn-form-help">
								<?php
								echo wp_kses_post(
									\Learnomy\Admin\UX::taxonomy_empty_help(
										(string) $qz_tax['label'],
										admin_url( 'admin.php?page=learnomy-categories&tag_type=quiz' )
									)
								);
								?>
							</p>
						<?php else : ?>
							<ul class="lrn-tag-checklist">
								<?php foreach ( $qz_tax_terms as $qz_term ) : ?>
									<li>
										<label class="lrn-checkbox-label">
											<input type="checkbox" name="tag_ids[]" value="<?php echo absint( $qz_term->id ); ?>"
												<?php checked( in_array( (int) $qz_term->id, $quiz_tag_ids, true ) ); ?>>
											<span><?php echo esc_html( $qz_term->name ); ?></span>
										</label>
									</li>
								<?php endforeach; ?>
							</ul>
							<?php if ( ! empty( $qz_tax['help'] ) ) : ?>
								<p class="lrn-form-help"><?php echo esc_html( $qz_tax['help'] ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<div class="lrn-tag-quick-add" data-tax-type="<?php echo esc_attr( $qz_tax['type'] ); ?>">
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

			<!-- ── Details card (read-only metadata) ──────────────────── -->
			<div class="lrn-admin-card">
				<h3 class="lrn-admin-card__title">
					<i data-lucide="info" class="lrn-icon--sm"></i>
					<?php esc_html_e( 'Details', 'learnomy' ); ?>
				</h3>
				<dl class="lrn-meta-list">
					<div class="lrn-meta-list__row">
						<dt><?php esc_html_e( 'Quiz ID', 'learnomy' ); ?></dt>
						<dd><?php echo absint( $quiz->id ); ?></dd>
					</div>
					<div class="lrn-meta-list__row">
						<dt><?php esc_html_e( 'Status', 'learnomy' ); ?></dt>
						<dd><?php echo esc_html( \Learnomy\Admin\UX::status_label( (string) ( $quiz->status ?? 'draft' ) ) ); ?></dd>
					</div>
					<div class="lrn-meta-list__row">
						<dt><?php esc_html_e( 'Mode', 'learnomy' ); ?></dt>
						<dd><?php echo esc_html( \Learnomy\Admin\UX::enum_label( (string) ( $quiz->mode ?? 'graded' ), 'quiz_mode' ) ); ?></dd>
					</div>
					<?php if ( ! empty( $quiz->created_at ) && '0000-00-00 00:00:00' !== $quiz->created_at ) : ?>
						<div class="lrn-meta-list__row">
							<dt><?php esc_html_e( 'Created', 'learnomy' ); ?></dt>
							<dd><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $quiz->created_at . ' UTC' ) ) ); ?></dd>
						</div>
					<?php endif; ?>
				</dl>
			</div>

			</div><!-- /.lrn-course-form__sidebar -->
			</div><!-- /.lrn-course-form__layout -->

			<?php require LEARNOMY_DIR . 'includes/admin/views/partials/question-picker-modal.php'; ?>
		<?php else : ?>
			<!-- Create mode: attach questions during creation. The "Add selected"
				button auto-saves the new quiz first (BC #9905191822). -->
			<div class="lrn-admin-card">
				<div class="lrn-quiz-editor__questions-head">
					<div>
						<h3 class="lrn-admin-card__title">
							<i data-lucide="list-checks" class="lrn-icon--sm"></i>
							<?php esc_html_e( 'Questions', 'learnomy' ); ?>
						</h3>
						<p class="lrn-admin-card__desc"><?php esc_html_e( 'Add questions from the bank now - just name the quiz first and it saves automatically.', 'learnomy' ); ?></p>
					</div>
				</div>
				<div class="lrn-quiz-slots" id="lrn-quiz-slots" data-quiz-id="0">
					<div class="lrn-quiz-slots__empty">
						<i data-lucide="inbox" aria-hidden="true"></i>
						<p><?php esc_html_e( 'No questions yet. Name the quiz above, then add some from the question bank.', 'learnomy' ); ?></p>
					</div>
				</div>
				<div class="lrn-quiz-picker-actions">
					<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-open-question-picker">
						<i data-lucide="plus"></i> <?php esc_html_e( 'Add questions from bank', 'learnomy' ); ?>
					</button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-question-editor' ) ); ?>" class="lrn-admin-btn" target="_blank" rel="noopener">
						<i data-lucide="external-link"></i> <?php esc_html_e( 'Create new question', 'learnomy' ); ?>
					</a>
				</div>
			</div>

			<?php require LEARNOMY_DIR . 'includes/admin/views/partials/question-picker-modal.php'; ?>
		<?php endif; ?>
	</form>
</div>
<?php
// Assets (drag-drop.css + quiz-editor.js) are enqueued in Admin::enqueue_assets()
// for the quiz-editor screens — keep this template logic-free.
?>
