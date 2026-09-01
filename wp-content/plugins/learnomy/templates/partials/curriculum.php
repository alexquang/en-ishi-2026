<?php
/**
 * Course curriculum outline partial.
 *
 * Expects:
 *   $course_id (int)      — the course to render curriculum for
 *   $user_id   (int|null) — current user ID, or 0/null if not logged in
 *
 * When $user_id is provided and the user is enrolled, each lesson shows a
 * completion checkmark. Section counts update to reflect completed lessons.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Section;
use Learnomy\Models\Lesson;
use Learnomy\Models\Lesson_Unlock;
use Learnomy\Models\Progress;
use Learnomy\Services\Lesson_Service;

if ( empty( $course_id ) ) {
	return;
}

$user_id  = isset( $user_id ) ? (int) $user_id : 0;
$sections = Section::get_for_course( (int) $course_id );

// Build a lookup of completed lesson IDs for this user.
$completed_ids = [];
if ( $user_id ) {
	$progress_rows = Progress::get_all_for_course( $user_id, (int) $course_id );
	foreach ( $progress_rows as $row ) {
		if ( 'completed' === $row->status ) {
			$completed_ids[ (int) $row->item_id ] = true;
		}
	}
}

// Course-level `require_sequential` gates clickability: the first incomplete
// lesson becomes the current cursor; every lesson after it is locked until
// the student completes it. Admins and instructors (course author) bypass
// the gate so they can preview/review the full curriculum.
$course_settings    = Course::get_all_settings( (int) $course_id );
$require_sequential = ! empty( $course_settings['require_sequential'] );
$bypass_locks       = current_user_can( 'manage_options' );
if ( ! $bypass_locks && $user_id ) {
	$course_row   = Course::find( (int) $course_id );
	$bypass_locks = $course_row && (int) ( $course_row->author_id ?? 0 ) === $user_id;
}

// Draft (unpublished) lessons are previewable only by people who can EDIT the
// course — its author, a co-instructor, or a site admin. Students and logged-out
// visitors must never see them in the outline. Lesson::get_for_*() now default
// to status='published' at the MODEL (Basecamp 10124162733 — the guard used to
// live only at fetch sites like this one, and every other caller leaked), so
// this partial passes null for editors to opt back into drafts for preview;
// $lrn_only_visible_lessons stays as belt-and-suspenders for any row set that
// arrives pre-fetched.
$lrn_can_edit_course = current_user_can( 'manage_options' );
if ( ! $lrn_can_edit_course && $user_id ) {
	$lrn_edit_course_row = isset( $course_row ) ? $course_row : Course::find( (int) $course_id );
	$lrn_can_edit_course = (
		( $lrn_edit_course_row && (int) ( $lrn_edit_course_row->author_id ?? 0 ) === $user_id )
		|| in_array(
			$user_id,
			array_map( 'intval', wp_list_pluck( \Learnomy\Models\CourseInstructor::get_for_course( (int) $course_id ), 'user_id' ) ),
			true
		)
	);
}
// null = every status (editor preview); 'published' = student view. Passed to
// every Lesson::get_*() fetch in this partial.
$lrn_curriculum_status = $lrn_can_edit_course ? null : 'published';

// Whether the viewer can actually OPEN these lessons.
//
// Until this existed the outline linked every row to every visitor, so a $79
// course showed 17 live links and one "Preview" badge that were visually
// identical; clicking any of them landed on "Enrollment Required". The outline
// promised what the lesson then refused.
//
// `access_course` is the canonical question and the SAME one the lesson page
// asks (Single_Lesson_View_Data), so the two screens cannot disagree. It folds
// in enrolment, membership coverage, Spaces and authorship — which is why this
// is not a price check: a member whose plan covers this course, or a student
// enrolled through a learning path, has no purchase and no padlock either.
// Getting that backwards is Basecamp 10186511098 in the other direction.
//
// Deliberately not price-gated: a logged-out visitor is refused at a FREE
// course's lessons too, so those rows are locked for them as well. The lock
// says "not yet", not "pay up", and the Enroll button above says how.
//
// Asks about the REAL viewer, not $user_id. The single-course template renders
// this partial twice — once for the enrolled view and once for the sales view,
// and the sales include deliberately zeroes $user_id so the outline drops
// checkmarks and per-student drip dates. That is the right call for PROGRESS,
// which is personal, and the wrong one for ACCESS, which is not: reading the
// zeroed id here would padlock a member's own curriculum on the very page
// selling it to them. Whether you may open a lesson does not depend on which
// half of the template is doing the rendering.
$lrn_access_viewer_id  = get_current_user_id();
$lrn_has_course_access = $lrn_can_edit_course
	|| ( $lrn_access_viewer_id && \Learnomy\Permissions\Permission_Engine::can( $lrn_access_viewer_id, 'access_course', (int) $course_id ) );

$lrn_only_visible_lessons = static function ( array $rows ) use ( $lrn_can_edit_course ): array {
	if ( $lrn_can_edit_course ) {
		return $rows;
	}
	return array_values(
		array_filter(
			$rows,
			static function ( $row ) {
				return 'published' === (string) ( $row->status ?? 'published' );
			}
		)
	);
};

// Resolve drip-schedule lock state for a lesson or topic row. Returns a
// 4-tuple: [ locked (bool), unlocks_at_iso (string), drip_mode (string),
// drip_offset_days (int) ] so the chip can show the right copy for each
// state — fixed-date countdown, relative-drip with concrete per-student
// row, or relative-drip pre-enrollment ("Unlocks N days after enroll").
$lrn_drip_state = static function ( $row, int $viewer_id ): array {
	if ( ! is_object( $row ) || empty( $row->id ) ) {
		return array( false, '', 'fixed', 0 );
	}
	// Row variant: this loop already holds the lesson/topic object, so the
	// id-based call would re-SELECT a row we have — one query per row, paid
	// only by students (admins bypass before that query). See
	// Lesson_Service::is_row_available_to(). Basecamp 10119142606.
	$is_locked = ! Lesson_Service::is_row_available_to( $row, $viewer_id );

	$drip_mode   = isset( $row->drip_mode ) ? (string) $row->drip_mode : 'fixed';
	$offset_days = isset( $row->drip_offset_days ) ? (int) $row->drip_offset_days : 0;

	if ( ! $is_locked ) {
		return array( false, '', $drip_mode, $offset_days );
	}

	$when = '';
	if ( 'relative' === $drip_mode ) {
		// Per-student unlock row carries the concrete UTC datetime once the
		// student has enrolled. Pre-enrollment viewers fall through to the
		// offset-only branch with an empty ISO string.
		if ( $viewer_id > 0 ) {
			$unlock = Lesson_Unlock::find_for_user_lesson( $viewer_id, (int) $row->id );
			if ( $unlock && ! empty( $unlock->unlocks_at ) ) {
				$when = (string) $unlock->unlocks_at;
			}
		}
	} elseif ( ! empty( $row->available_date ) ) {
		// Fixed-date drip — the lesson row carries the calendar datetime.
		$when = (string) $row->available_date;
	} elseif ( ! empty( $row->section_id ) ) {
		// Section-level drip fallback — only consulted when the lesson row
		// has no available_date of its own (per-lesson overrides win).
		$section = Section::find( (int) $row->section_id );
		if ( $section && ! empty( $section->available_date ) ) {
			$when = (string) $section->available_date;
		}
	}

	$iso = '';
	if ( $when ) {
		try {
			$utc = new \DateTimeImmutable( $when, new \DateTimeZone( 'UTC' ) );
			$iso = $utc->format( 'c' );
		} catch ( \Exception $e ) {
			$iso = '';
		}
	}
	return array( true, $iso, $drip_mode, $offset_days );
};

// Walk every lesson in the course in curriculum order to resolve the cursor.
$locked_lesson_ids = [];
if ( $require_sequential && $user_id && ! $bypass_locks ) {
	$all_lessons_ordered = $lrn_only_visible_lessons( Lesson::get_for_course( (int) $course_id, 0, 0, $lrn_curriculum_status ) );
	$found_incomplete    = false;
	foreach ( $all_lessons_ordered as $walk_lesson ) {
		$walk_done = isset( $completed_ids[ (int) $walk_lesson->id ] );
		if ( $found_incomplete ) {
			$locked_lesson_ids[ (int) $walk_lesson->id ] = true;
			continue;
		}
		if ( ! $walk_done ) {
			$found_incomplete = true;
		}
	}
}

// One quiz row, rendered identically wherever a quiz sits in the outline: under
// its lesson, at the end of its section, or in the final-assessment block. It
// was three copies of the same markup, which is how the section copy spent a
// release printing no pass mark (it read a column name that does not exist)
// while the others printed it correctly.
$lrn_render_quiz_row = static function ( $quiz, string $modifier = '', bool $is_locked = false, string $lock_reason = '' ): void {
	// Default reason covers the lesson-nested case, which is where a locked
	// quiz came from before course access could lock one on its own.
	if ( '' === $lock_reason ) {
		$lock_reason = __( 'This quiz unlocks with its lesson.', 'learnomy' );
	}
	// Quiz::url() is the one helper, per the 1.9.2 URL consolidation: it returns
	// the course route for a course-attached quiz and the standalone route
	// otherwise. Building the URL here from a course slug instead meant that
	// whenever the slug was not available this printed `/courses//quiz/<slug>/`
	// — a broken link whose label reads correctly, which is the exact class the
	// route-arg hardening was added to surface.
	$quiz_url = \Learnomy\Models\Quiz::url( $quiz );
	?>
	<li class="lrn-curriculum__quiz<?php echo esc_attr( $modifier ); ?><?php echo esc_attr( $is_locked ? ' lrn-curriculum__quiz--locked' : '' ); ?>">
		<?php if ( $is_locked ) : ?>
			<span class="lrn-curriculum__quiz-icon lrn-curriculum__lock" aria-hidden="true">
				<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
			</span>
		<?php else : ?>
			<span class="lrn-curriculum__quiz-icon" aria-hidden="true"><i data-lucide="clipboard-check" aria-hidden="true"></i></span>
		<?php endif; ?>
		<?php if ( $is_locked ) : ?>
			<span class="is-locked lrn-curriculum__title--locked" aria-disabled="true" title="<?php echo esc_attr( $lock_reason ); ?>"><?php echo esc_html( $quiz->title ); ?></span>
		<?php else : ?>
			<a href="<?php echo esc_url( $quiz_url ); ?>"><?php echo esc_html( $quiz->title ); ?></a>
		<?php endif; ?>
		<span class="lrn-badge lrn-badge--blue"><?php esc_html_e( 'Quiz', 'learnomy' ); ?></span>
		<?php // passing_score, not pass_mark — the schema column is passing_score; the phantom name meant section quizzes never showed their pass %, while the flat branch (using the right column) did (half-cooked audit P1). ?>
		<?php if ( ! empty( $quiz->passing_score ) ) : ?>
			<?php /* translators: %d: passing score percentage */ ?>
			<span class="lrn-curriculum__quiz-pass"><?php echo esc_html( sprintf( __( 'Pass: %d%%', 'learnomy' ), (int) $quiz->passing_score ) ); ?></span>
		<?php endif; ?>
	</li>
	<?php
};

// Every course renders through ONE path, sectioned or not.
//
// There used to be a second, flat branch here for a course with no sections.
// It was a near-copy of the section renderer that had already drifted: it
// emitted neither the `.lrn-curriculum__section` card nor the
// `.lrn-curriculum__lesson-row` wrapper, so a sectionless course rendered its
// lessons as bare theme-blue links with no container, no padding and no
// dividers - beside a properly styled final-quiz card, which made the
// omission read as deliberate. It also never rendered sub-lessons at all, so
// a sectionless course silently hid every topic beneath its lessons.
//
// Nothing was needed to replace it: the loop below ALREADY groups
// section-less lessons into a pseudo-section, because a course can mix
// grouped and ungrouped lessons. A course with no sections is simply one
// where every lesson is ungrouped, so it falls through the same code and
// comes out identical to a sectioned one - card, rows, states, topics,
// quizzes and all.
?>
<div class="lrn-curriculum">
	<?php
	// Sections are optional: section-less lessons render in a leading
	// pseudo-section so they reach students whether or not anything else is
	// grouped. A course with NO sections is just the case where every lesson
	// lands here, which is what lets one renderer serve both.
	//
	// Its title depends on what it is sitting next to, because the same word
	// cannot do both jobs. Beside real sections, "Ungrouped" is the useful
	// thing to say - it tells the author which lessons they have not filed
	// yet. As the ONLY heading on a course that never used sections, it reads
	// as a defect, telling a buyer the syllabus is unfinished when the author
	// simply had no reason to group three lessons. There it says
	// "Curriculum": a neutral name any site owner can live with, and the same
	// word the heading above the block already uses.
	$lrn_loose_lessons   = $lrn_only_visible_lessons( Lesson::get_section_less_for_course( (int) $course_id, $lrn_curriculum_status ) );
	$lrn_render_sections = $sections;
	if ( $lrn_loose_lessons ) {
		array_unshift(
			$lrn_render_sections,
			(object) array(
				'id'    => 0,
				'title' => $sections
					? __( 'Ungrouped', 'learnomy' )
					: __( 'Curriculum', 'learnomy' ),
			)
		);
	}
	// Resolve every section's lessons BEFORE rendering, then fetch all their
	// topics in ONE query. Previously each lesson ran its own get_topics(), so
	// the outline cost one extra query per lesson — 12 of the 39 a student paid
	// on a 12-lesson course. Lesson::get_topics_for_parents() already existed
	// for the admin list; this reuses it rather than adding a second batcher.
	// Basecamp 10119142606.
	//
	// The lessons themselves were still fetched one section at a time, which is
	// the same N+1 one level up: a 30-section course ran 30 queries here before
	// rendering a line. Lesson::get_for_sections() answers the whole set in one
	// and returns the same section_id => rows map, so nothing downstream changes.
	// (Not top_level_for_course_by_section(), which takes no status and would
	// show students unpublished lessons.)
	$lrn_section_ids = array();
	foreach ( $lrn_render_sections as $lrn_pre_section ) {
		$lrn_sid = (int) $lrn_pre_section->id;
		if ( $lrn_sid > 0 ) {
			$lrn_section_ids[] = $lrn_sid;
		}
	}
	$lrn_section_lessons = Lesson::get_for_sections( $lrn_section_ids, $lrn_curriculum_status );

	$lrn_lessons_by_section = array();
	$lrn_all_lesson_ids     = array();
	foreach ( $lrn_render_sections as $lrn_pre_section ) {
		$lrn_sid                            = (int) $lrn_pre_section->id;
		$lrn_lessons_by_section[ $lrn_sid ] = ( 0 === $lrn_sid )
			? $lrn_loose_lessons
			: $lrn_only_visible_lessons( $lrn_section_lessons[ $lrn_sid ] ?? array() );

		foreach ( $lrn_lessons_by_section[ $lrn_sid ] as $lrn_pre_lesson ) {
			$lrn_all_lesson_ids[] = (int) $lrn_pre_lesson->id;
		}
	}
	$lrn_topics_by_lesson = Lesson::get_topics_for_parents( $lrn_all_lesson_ids, $lrn_curriculum_status );

	// Same again for section quizzes, which the render loop below fetched one
	// section at a time. Quiz::get_for_sections() mirrors the Lesson batcher.
	$lrn_quizzes_by_section = \Learnomy\Models\Quiz::get_for_sections( $lrn_section_ids, $lrn_curriculum_status );

	foreach ( $lrn_render_sections as $section ) :
		$lessons       = $lrn_lessons_by_section[ (int) $section->id ] ?? array();
		$total_lessons = count( $lessons );
		$done_count    = 0;
		$lesson_ids    = array();
		foreach ( $lessons as $l ) {
			$lesson_ids[] = (int) $l->id;
			if ( isset( $completed_ids[ (int) $l->id ] ) ) {
				++$done_count;
			}
		}

		// Honour where the author put each quiz. The Lesson field in the quiz
		// editor offers "End of section" as its empty option, so a quiz that
		// names a lesson is that lesson's quiz and belongs under it — listing
		// every quiz after the lesson list told students the module ends with a
		// quiz that actually sits inside lesson 1 (Basecamp 10181082400).
		$lrn_quiz_groups        = \Learnomy\Models\Quiz::split_by_lesson(
			$lrn_quizzes_by_section[ (int) $section->id ] ?? array(),
			$lesson_ids
		);
		$lrn_lesson_quiz_map    = $lrn_quiz_groups['by_lesson'];
		$lrn_end_of_sec_quizzes = $lrn_quiz_groups['section_end'];
		?>
		<?php
		// A card title distinguishes a card from its SIBLINGS. A course that
		// never used sections has exactly one card, so there is nothing to
		// distinguish and no name the author ever chose - and every generic
		// word we tried simply echoed the "Curriculum" heading directly above
		// it. So that card carries no title strip at all: the heading names the
		// block, the card holds the lessons.
		//
		// It is a plain <div> rather than <details> in that case, because a
		// <details> with no <summary> gets the browser's own "Details" label,
		// and there is nothing to collapse when the card IS the outline.
		$lrn_untitled_card = ( 0 === (int) $section->id ) && empty( $sections );
		?>
		<?php if ( $lrn_untitled_card ) : ?>
		<div class="lrn-curriculum__section lrn-curriculum__section--untitled">
		<?php else : ?>
		<details class="lrn-curriculum__section" <?php echo esc_attr( ( $done_count < $total_lessons ) ? 'open' : '' ); ?>>
			<summary class="lrn-curriculum__section-title">
				<span><?php echo esc_html( $section->title ); ?></span>
				<span class="lrn-curriculum__section-count">
					<?php if ( $user_id && $total_lessons > 0 ) : ?>
						<?php echo esc_html( $done_count . '/' . $total_lessons ); ?>
					<?php else : ?>
						<?php
						/* translators: %d: number of lessons in the section */
						echo esc_html( sprintf( _n( '%d lesson', '%d lessons', $total_lessons, 'learnomy' ), $total_lessons ) );
						?>
					<?php endif; ?>
				</span>
			</summary>
		<?php endif; ?>

			<?php if ( $lessons ) : ?>
				<ol class="lrn-curriculum__lessons">
					<?php
					foreach ( $lessons as $lesson ) :
						$course_obj = isset( $course ) ? $course : null;
						// See the sibling loop above: Lesson::get_permalink() is the guarded
						// builder and returns null rather than a half-built path.
						$lesson_url    = (string) ( Lesson::get_permalink( $lesson, $course_obj ) ?? '' );
						$is_done       = isset( $completed_ids[ (int) $lesson->id ] );
						$is_preview    = ! empty( $lesson->is_preview );
						$is_seq_locked = isset( $locked_lesson_ids[ (int) $lesson->id ] );

						[ $is_drip_locked, $drip_iso, $drip_mode, $drip_offset_days ] = $lrn_drip_state( $lesson, (int) $user_id );
						// A free preview stays open to everyone — that is the whole point
						// of marking it preview, and it is the row a prospect is meant to
						// click.
						$is_access_locked = ! $lrn_has_course_access && ! $is_preview;
						$is_locked        = $is_seq_locked || $is_drip_locked || $is_access_locked;
						$show_offset_msg  = ( $is_drip_locked && '' === $drip_iso && 'relative' === $drip_mode && $drip_offset_days > 0 );

						// Why this row is locked, most fundamental reason first: no access
						// to the course at all beats "not your turn yet", which beats
						// "scheduled for later". Telling a visitor to finish the previous
						// lessons when they do not even have the course is the kind of
						// advice that reads as a broken page.
						if ( $is_access_locked ) {
							$lock_reason = __( 'Enroll in this course to open this lesson.', 'learnomy' );
						} elseif ( $is_drip_locked ) {
							$lock_reason = __( 'This lesson is on a drip schedule and is not yet available.', 'learnomy' );
						} else {
							$lock_reason = __( 'Complete the previous lessons to unlock this one.', 'learnomy' );
						}

						$topics       = $lrn_topics_by_lesson[ (int) $lesson->id ] ?? array();
						$content_type = $lesson->content_type ?? 'text';
						// Empty string when the author declared no length, and the row
						// then shows nothing — better than "0 min", which reads as a
						// claim about the lesson rather than a gap in the data.
						$duration_label = \Learnomy\format_duration_label( \Learnomy\lesson_duration_seconds( $lesson ) );
						// Lucide icon name — resolved in the icon span below. Uses the
						// canonical content-type map so audio/pdf/embed/live/download/
						// mediashield each get their own icon (not the text fallback).
						$type_icon = Lesson::content_type_icon( (string) $content_type );

						$lesson_classes = 'lrn-curriculum__lesson';
						if ( $is_done ) {
							$lesson_classes .= ' lrn-curriculum__lesson--done';
						} elseif ( $is_locked ) {
							$lesson_classes .= ' lrn-curriculum__lesson--locked';
						}
						?>
						<li class="<?php echo esc_attr( $lesson_classes ); ?>">
							<div class="lrn-curriculum__lesson-row">
								<?php if ( $is_done ) : ?>
									<span class="lrn-curriculum__check" aria-label="<?php esc_attr_e( 'Completed', 'learnomy' ); ?>">&#10003;</span>
								<?php elseif ( $is_locked ) : ?>
									<span class="lrn-curriculum__icon lrn-curriculum__lock" aria-hidden="true">
										<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
									</span>
								<?php else : ?>
									<span class="lrn-curriculum__icon" aria-hidden="true">
										<i data-lucide="<?php echo esc_attr( $type_icon ); ?>" aria-hidden="true"></i>
									</span>
								<?php endif; ?>
								<?php if ( $is_locked ) : ?>
									<span class="is-locked lrn-curriculum__title--locked" aria-disabled="true" title="<?php echo esc_attr( $lock_reason ); ?>"><?php echo esc_html( $lesson->title ); ?></span>
								<?php elseif ( '' !== $lesson_url ) : ?>
									<a href="<?php echo esc_url( $lesson_url ); ?>"><?php echo esc_html( $lesson->title ); ?></a>
								<?php else : ?>
									<?php // See the sibling loop: no resolvable course route, so no link. ?>
									<span class="lrn-curriculum__title"><?php echo esc_html( $lesson->title ); ?></span>
								<?php endif; ?>
								<?php if ( $is_drip_locked && $drip_iso ) : ?>
									<span class="lrn-countdown-chip"
										data-lrn-countdown
										data-unlocks-at="<?php echo esc_attr( $drip_iso ); ?>"
										role="timer"
										aria-live="polite">
										<?php esc_html_e( 'Locked', 'learnomy' ); ?>
									</span>
								<?php elseif ( $show_offset_msg ) : ?>
									<span class="lrn-countdown-chip lrn-countdown-chip--relative">
										<?php
										printf(
											esc_html(
												/* translators: %d: number of days after enrollment */
												_n(
													'Unlocks %d day after enrollment',
													'Unlocks %d days after enrollment',
													$drip_offset_days,
													'learnomy'
												)
											),
											(int) $drip_offset_days
										);
										?>
									</span>
								<?php endif; ?>
								<?php
								// Shown to anyone WITHOUT access, not just logged-out
								// visitors. It was `! $user_id`, so a logged-in prospect
								// comparing courses saw the one open row with no badge
								// explaining why it was open. To someone already enrolled
								// the badge is noise: every row opens for them.
								if ( $is_preview && ! $lrn_has_course_access ) :
									?>
									<span class="lrn-badge lrn-badge--green"><?php esc_html_e( 'Preview', 'learnomy' ); ?></span>
								<?php endif; ?>
								<?php
								// Shown only when it is NOT a plain text lesson.
								// "Text" on every row of a text course is the
								// absence of information: it tells a reader that a
								// lesson made of words is made of words, in a
								// column where they were looking for what makes
								// each lesson different. Video, audio, a PDF, a
								// live session or a download all change what the
								// reader is about to open, so those keep saying so.
								if ( 'text' !== (string) $content_type ) :
									?>
									<span class="lrn-curriculum__type-label"><?php echo esc_html( Lesson::content_type_label( (string) $content_type ) ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $duration_label ) : ?>
									<span class="lrn-curriculum__duration"><?php echo esc_html( $duration_label ); ?></span>
								<?php endif; ?>
							</div>
							<?php if ( $topics ) : ?>
								<ul class="lrn-curriculum__topics">
									<?php
									foreach ( $topics as $topic ) :
										// A topic is a fragment of its lesson's page, so with no
										// lesson URL there is nothing to anchor into: concatenating
										// would yield a bare "#topic-N" pointing at the current page.
										$topic_url        = '' === $lesson_url ? '' : $lesson_url . '#topic-' . absint( $topic->id );
										$topic_seq_locked = isset( $locked_lesson_ids[ (int) $topic->id ] );

										[ $topic_drip_locked, $topic_drip_iso, $topic_drip_mode, $topic_offset_days ] = $lrn_drip_state( $topic, (int) $user_id );
										// A topic is a fragment of its lesson's page: if the
										// lesson will not open, neither will the anchor into
										// it. A topic can carry its own preview flag, so an
										// author can open one section of an otherwise closed
										// lesson.
										$topic_access_locked   = ! $lrn_has_course_access && empty( $topic->is_preview ) && ! $is_preview;
										$topic_locked          = $topic_seq_locked || $topic_drip_locked || $topic_access_locked;
										$topic_show_offset_msg = ( $topic_drip_locked && '' === $topic_drip_iso && 'relative' === $topic_drip_mode && $topic_offset_days > 0 );

										if ( $topic_access_locked ) {
											$topic_lock_reason = __( 'Enroll in this course to open this topic.', 'learnomy' );
										} elseif ( $topic_drip_locked ) {
											$topic_lock_reason = __( 'This topic is on a drip schedule and is not yet available.', 'learnomy' );
										} else {
											$topic_lock_reason = __( 'Complete the previous lessons to unlock this one.', 'learnomy' );
										}
										?>
										<li class="lrn-curriculum__topic<?php echo esc_attr( $topic_locked ? ' lrn-curriculum__topic--locked' : '' ); ?>">
											<span class="lrn-curriculum__topic-icon" aria-hidden="true">&#8212;</span>
											<?php if ( $topic_locked ) : ?>
												<span class="is-locked lrn-curriculum__title--locked" aria-disabled="true" title="<?php echo esc_attr( $topic_lock_reason ); ?>"><?php echo esc_html( $topic->title ); ?></span>
											<?php elseif ( '' !== $topic_url ) : ?>
												<a href="<?php echo esc_url( $topic_url ); ?>"><?php echo esc_html( $topic->title ); ?></a>
											<?php else : ?>
												<span class="lrn-curriculum__title"><?php echo esc_html( $topic->title ); ?></span>
											<?php endif; ?>
											<?php if ( $topic_drip_locked && $topic_drip_iso ) : ?>
												<span class="lrn-countdown-chip"
													data-lrn-countdown
													data-unlocks-at="<?php echo esc_attr( $topic_drip_iso ); ?>"
													role="timer"
													aria-live="polite">
													<?php esc_html_e( 'Locked', 'learnomy' ); ?>
												</span>
											<?php elseif ( $topic_show_offset_msg ) : ?>
												<span class="lrn-countdown-chip lrn-countdown-chip--relative">
													<?php
													printf(
														esc_html(
															/* translators: %d: number of days after enrollment */
															_n(
																'Unlocks %d day after enrollment',
																'Unlocks %d days after enrollment',
																$topic_offset_days,
																'learnomy'
															)
														),
														(int) $topic_offset_days
													);
													?>
												</span>
											<?php endif; ?>
											<?php
											$topic_duration = \Learnomy\format_duration_label( \Learnomy\lesson_duration_seconds( $topic ) );
											if ( '' !== $topic_duration ) :
												?>
												<span class="lrn-curriculum__duration"><?php echo esc_html( $topic_duration ); ?></span>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<?php
							// This lesson's own quizzes, nested like its topics so the
							// outline reads in the order the student meets it: lesson,
							// its topics, then the quiz the lesson ends with.
							$lrn_this_lesson_quizzes = $lrn_lesson_quiz_map[ (int) $lesson->id ] ?? array();
							if ( $lrn_this_lesson_quizzes ) :
								?>
								<ul class="lrn-curriculum__lesson-quizzes">
									<?php
									foreach ( $lrn_this_lesson_quizzes as $lrn_lesson_quiz ) {
										$lrn_render_quiz_row( $lrn_lesson_quiz, ' lrn-curriculum__quiz--in-lesson', $is_locked );
									}
									?>
								</ul>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<?php
			// Quizzes that close out THIS section — the ones whose author left the
			// Lesson field on "End of section". Lesson-bound quizzes already
			// rendered under their lesson above. An earlier implementation pulled
			// every quiz for the course and dumped them under the last section, so
			// an empty Section 2 created in Course Builder appeared to "magically"
			// contain quizzes that belonged to Section 1 (bug card #8); quizzes
			// with a NULL section_id (course-level) are emitted in the
			// final-assessment block outside this loop, below.
			//
			// Draft quizzes are for editors only, exactly like draft lessons above —
			// $lrn_curriculum_status is null for an editor and 'published' for
			// everyone else (Basecamp 10135041249).
			if ( ! empty( $section->id ) && $lrn_end_of_sec_quizzes ) :
				?>
				<ul class="lrn-curriculum__section-quizzes">
				<?php
				foreach ( $lrn_end_of_sec_quizzes as $quiz ) {
					// A quiz is course content like any other row: with no access to the
					// course it must not look clickable either.
					$lrn_render_quiz_row( $quiz, '', ! $lrn_has_course_access, __( 'Enroll in this course to open this quiz.', 'learnomy' ) );
				}
				?>
				</ul>
				<?php
			endif;
			?>
		<?php if ( $lrn_untitled_card ) : ?>
		</div>
		<?php else : ?>
		</details>
		<?php endif; ?>
	<?php endforeach; ?>

	<?php
	// J-03.2 — course-level quizzes (course_id set, section_id NULL) used
	// to be invisible to students because the section loop above only
	// emits section-scoped quizzes. The original docblock promised a
	// "separate footer block below" but it was never implemented, so the
	// final quiz on every Aurora course (per PR #134) showed up nowhere
	// in the curriculum. Now we list them under a "Final assessment"
	// header that follows the lesson sections.
	if ( isset( $course ) && ! empty( $course->id ) ) :
		// Same status guard as the section quizzes above — students and
		// visitors never see a draft final assessment (Basecamp 10135041249).
		$course_quizzes = \Learnomy\Models\Quiz::get_for_course(
			(int) $course->id,
			$lrn_curriculum_status
		);
		$course_quizzes = array_values(
			array_filter(
				$course_quizzes,
				static fn( $q ) => empty( $q->section_id )
			)
		);
		if ( $course_quizzes ) :
			?>
			<details class="lrn-curriculum__section lrn-curriculum__section--final" open>
				<summary class="lrn-curriculum__section-title">
					<span class="lrn-curriculum__section-label">
						<i data-lucide="award" aria-hidden="true"></i>
						<?php esc_html_e( 'Final assessment', 'learnomy' ); ?>
					</span>
					<span class="lrn-curriculum__section-count">
						<?php
						printf(
							/* translators: %d: number of final quizzes */
							esc_html( _n( '%d quiz', '%d quizzes', count( $course_quizzes ), 'learnomy' ) ),
							count( $course_quizzes )
						);
						?>
					</span>
				</summary>
				<ul class="lrn-curriculum__section-quizzes">
				<?php
				foreach ( $course_quizzes as $quiz ) {
					// A quiz is course content like any other row: with no access to the
					// course it must not look clickable either.
					$lrn_render_quiz_row( $quiz, '', ! $lrn_has_course_access, __( 'Enroll in this course to open this quiz.', 'learnomy' ) );
				}
				?>
				</ul>
			</details>
			<?php
		endif;
	endif;
	?>
</div>
