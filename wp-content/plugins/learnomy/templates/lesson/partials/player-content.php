<?php
/**
 * Lesson player — interactive wrapper: curriculum sidebar + main content.
 *
 * Shared by both lesson layout variants (focus, embedded). Owns the
 * `data-wp-interactive="learnomy/lesson-player"` wrapper: the collapsible
 * sidebar reopen/toggle controls, the curriculum sidebar (delegated to
 * partials/curriculum-sidebar.php), the main content column (content-type
 * switch → Editor.js body → completion strip + mark-complete → resources →
 * prev/next nav) and the lesson comments thread.
 *
 * The drip-locked branch closes the wrapper and `return`s from this include,
 * so this partial owns the full wrapper open/close to stay byte-identical
 * with the pre-extraction single template.
 *
 * Included from templates/lesson/focus.php and templates/lesson/embedded.php
 * via `include`, inheriting the including template's full variable scope
 * (the $view_data set + the completion/$progress_context locals computed by
 * the variant before the include).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// Bare `Course::` / `Lesson::` references below resolve via these imports —
// `use` statements do not carry over from the including template.
use Learnomy\Models\Course;
use Learnomy\Models\Lesson;
?>
<div class="lrn-container lrn-container--full lrn-lesson-player" data-wp-interactive="learnomy/lesson-player"
	data-wp-context='{"lessonId":<?php echo (int) $lesson->id; ?>,"courseId":<?php echo (int) $course->id; ?>}'>

<?php require \Learnomy\template_path( 'lesson/partials/curriculum-sidebar.php' ); ?>

	<?php
	// Single reopen control, shown only when the curriculum sidebar is collapsed
	// (desktop). It sits top-left, mirroring the collapse toggle that lives at the
	// top of the open sidebar — one expand affordance, not two. On tablet/mobile
	// this is hidden in favour of the sticky "Curriculum" topbar (see CSS).
	$action_icon  = 'panel-left-open';
	$action_label = null;
	$action_title = null;
	$action_href  = null;
	$action_aria  = __( 'Open sidebar', 'learnomy' );
	$action_class = 'lrn-player-sidebar-open';
	$action_attrs = [
		'data-wp-on--click'                               => 'actions.toggleSidebar',
		'data-wp-class--lrn-player-sidebar-open--visible' => 'state.sidebarCollapsed',
	];
	require \Learnomy\template_path( 'partials/lrn-action.php' );
	?>

	<?php
	// Slim sticky lesson bar — shown only on tablet/mobile (CSS), where the
	// curriculum is an off-canvas drawer. It carries one clear "Curriculum"
	// control plus the course name for context, so no floating button has to
	// sit on top of the lesson title. On desktop the sidebar is always visible
	// and this bar is hidden. Lives in the player wrapper (the
	// `learnomy/lesson-player` interactivity scope) so the toggle resolves.
	?>
	<div class="lrn-player-topbar">
		<button type="button" class="lrn-player-topbar__toggle"
			data-wp-on--click="actions.toggleSidebar"
			aria-label="<?php esc_attr_e( 'Show curriculum', 'learnomy' ); ?>">
			<i data-lucide="panel-left-open" aria-hidden="true"></i>
			<span><?php esc_html_e( 'Curriculum', 'learnomy' ); ?></span>
		</button>
		<span class="lrn-player-topbar__title"><?php echo esc_html( $course->title ); ?></span>
	</div>

	<!-- Main content area -->
	<main class="lrn-player-content" id="lrn-lesson-content"
			aria-label="<?php esc_attr_e( 'Lesson content', 'learnomy' ); ?>"
			data-wp-interactive="learnomy/progress"
			data-wp-context='<?php echo esc_attr( wp_json_encode( $progress_context ) ); ?>'
			data-wp-init="callbacks.onInit">

		<?php
		// Admin / instructor preview notice — focus.php sets $show_admin_preview
		// so the bar renders INSIDE the main content column instead of floating
		// over the curriculum sidebar. embedded.php inlines its own copy above
		// the layout wrap. Basecamp #9925339019.
		if ( ! empty( $show_admin_preview ) ) :
			?>
			<div class="lrn-admin-preview-bar lrn-admin-preview-bar--inline">
				<i data-lucide="eye" aria-hidden="true"></i>
				<?php if ( $is_admin ) : ?>
					<?php printf( esc_html( /* translators: %s: singular label for a student (e.g. "Learner"). */ __( 'Admin preview. You are viewing this lesson without enrollment. %s data is not affected.', 'learnomy' ) ), esc_html( \Learnomy\student_label() ) ); ?>
				<?php else : ?>
					<?php printf( esc_html( /* translators: 1: singular instructor label, 2: plural student label. */ __( '%1$s preview. This is your course. %2$s see the same content.', 'learnomy' ) ), esc_html( \Learnomy\instructor_label() ), esc_html( \Learnomy\student_label( true ) ) ); ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php
		// One-shot notice when the view-data layer redirected a student away
		// from a locked lesson (Basecamp #9925276246). The redirect lands on
		// the most recent unlocked lesson and appends ?lesson_locked=1.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['lesson_locked'] ) ) :
			?>
			<div class="lrn-banner lrn-banner--warning" role="status">
				<strong><?php esc_html_e( 'That lesson is still locked.', 'learnomy' ); ?></strong>
				<?php esc_html_e( 'Complete the current lesson to unlock the next one.', 'learnomy' ); ?>
			</div>
		<?php endif; ?>

		<?php
		// Drip schedule — when the lesson has not unlocked yet for the
		// current viewer, render the locked countdown card and stop the
		// rest of the player from rendering. Admins and the course author
		// bypass this gate inside Lesson_Service::is_available_to() so
		// they can preview drip lessons without time-travel.
		if ( ! empty( $locked ) ) :
			$lrn_locked_partial = \Learnomy\template_path( 'partials/lesson-locked.php' );
			if ( file_exists( $lrn_locked_partial ) ) {
				include $lrn_locked_partial;
			}
			?>
		</main>
	</div>
			<?php
			return;
		endif;
		?>

		<h1 class="lrn-player-content__title"><?php echo esc_html( $lesson->title ); ?></h1>

		<?php
		// Content-type media block (video / audio / pdf / embed / download /
		// live). Extracted to partials/media.php so the sidebar embedded
		// layout reuses the exact same markup — Plyr bindings + skeleton
		// preserved. Sets $content_type + $media_url for the body branch below.
		require \Learnomy\template_path( 'lesson/partials/media.php' );
		?>

		<?php if ( ! empty( $lesson->content ) ) : ?>
			<div class="lrn-player-content__body">
				<?php echo \Learnomy\render_editorjs_content( $lesson->content, $lesson->title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_editorjs_content uses wp_kses_post internally. ?>
			</div>
		<?php elseif ( 'text' === $content_type ) : ?>
			<?php
			/*
			 * check 10: empty_state — text-type lesson with no body content.
			 * Admins and instructors see a prompt to add content; students see
			 * a "content coming soon" notice. Both avoid a completely blank area.
			 */
			?>
			<div class="lrn-player-content__body lrn-player-content__body--empty">
				<div class="lrn-empty-state">
					<?php if ( $is_admin || $is_instructor ) : ?>
						<svg class="lrn-empty-state__icon" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
						<p class="lrn-empty-state__title"><?php esc_html_e( 'No lesson content yet', 'learnomy' ); ?></p>
						<p class="lrn-empty-state__desc"><?php esc_html_e( 'Open the lesson editor to add content.', 'learnomy' ); ?></p>
					<?php else : ?>
						<svg class="lrn-empty-state__icon" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
						<p class="lrn-empty-state__title"><?php esc_html_e( 'Content coming soon', 'learnomy' ); ?></p>
						<p class="lrn-empty-state__desc"><?php esc_html_e( 'Your instructor has not added content to this lesson yet. Check back later.', 'learnomy' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>

		<?php
		/**
		 * Allow Pro modules to render auxiliary tools alongside the lesson
		 * (e.g. notes panel, bookmark toggle). Listeners receive the lesson
		 * object and the enrolled flag. Plan 00 §7 Phase 2.
		 *
		 * @param object $lesson      Lesson row.
		 * @param bool   $is_enrolled True when the current viewer has an active enrollment.
		 */
		do_action( 'learnomy_lesson_player_aside', $lesson, $is_enrolled );
		?>

		<?php
		// Completion strip + Mark Complete button + progress toast. Extracted
		// to partials/completion.php so the sidebar embedded layout reuses the
		// exact same `learnomy/progress` store bindings — mark-complete keeps
		// working unchanged.
		require \Learnomy\template_path( 'lesson/partials/completion.php' );
		?>

		<?php
		// Resources — downloadable attachments for this lesson. Rendered below
		// the lesson body so it sits between the content and the prev/next nav.
		// $lesson_attachments pre-resolved by Single_Lesson_View_Data.
		if ( ! empty( $lesson_attachments ) ) :
			?>
			<section class="lrn-lesson-resources" aria-labelledby="lrn-lesson-resources-heading">
				<h3 id="lrn-lesson-resources-heading" class="lrn-lesson-resources__heading">
					<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 17.93 8.8l-8.58 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
					<?php esc_html_e( 'Resources', 'learnomy' ); ?>
				</h3>
				<ul class="lrn-lesson-resources__list">
					<?php
					foreach ( $lesson_attachments as $attachment ) :
						// Gated delivery route, resolved by Single_Lesson_View_Data.
						$attachment_url = (string) ( $attachment->url ?? '' );
						if ( '' === $attachment_url ) {
							continue;
						}
						?>
						<li class="lrn-lesson-resources__item">
							<a class="lrn-lesson-resources__link"
								href="<?php echo esc_url( $attachment_url ); ?>"
								download="<?php echo esc_attr( $attachment->filename ); ?>"
								rel="noopener">
								<svg class="lrn-lesson-resources__icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 17.93 8.8l-8.58 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
								<span class="lrn-lesson-resources__name"><?php echo esc_html( $attachment->filename ); ?></span>
								<?php if ( ! empty( $attachment->size_bytes ) ) : ?>
									<span class="lrn-lesson-resources__size"><?php echo esc_html( size_format( (int) $attachment->size_bytes ) ); ?></span>
								<?php endif; ?>
								<svg class="lrn-lesson-resources__download" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<!-- Prev / next lesson navigation -->
		<?php
		// Respect the lesson's `lock_next_until_complete` flag: when the
		// lesson is not yet completed and the setting requires completion
		// before progressing, render the Next control as a disabled button.
		// Admins / instructors bypass the gate so they can move freely.
		$locks_next     = ! empty( $completion_status['locks_next'] ) && empty( $completion_status['is_completed'] );
		$bypass_locks   = $is_admin || $is_instructor;
		$next_is_locked = $locks_next && ! $bypass_locks;
		?>
		<?php if ( ! empty( $attached_quiz ) ) : ?>
			<aside class="lrn-lesson-quiz-cta" aria-label="<?php esc_attr_e( 'Lesson quiz', 'learnomy' ); ?>">
				<div class="lrn-lesson-quiz-cta__body">
					<p class="lrn-lesson-quiz-cta__label"><?php esc_html_e( 'Lesson quiz', 'learnomy' ); ?></p>
					<h3 class="lrn-lesson-quiz-cta__title"><?php echo esc_html( $attached_quiz->title ); ?></h3>
				</div>
				<a class="lrn-btn lrn-btn--primary lrn-lesson-quiz-cta__cta" href="<?php echo esc_url( $attached_quiz_url ); ?>">
					<?php esc_html_e( 'Take quiz', 'learnomy' ); ?>
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
				</a>
			</aside>
		<?php endif; ?>

		<nav class="lrn-lesson-nav" aria-label="<?php esc_attr_e( 'Lesson navigation', 'learnomy' ); ?>">
			<?php if ( $prev_url ) : ?>
				<a href="<?php echo esc_url( $prev_url ); ?>" class="lrn-lesson-nav__prev">
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
					<span>
						<small><?php esc_html_e( 'Previous', 'learnomy' ); ?></small>
						<strong><?php echo esc_html( $prev_lesson->title ); ?></strong>
					</span>
				</a>
			<?php else : ?>
				<span class="lrn-lesson-nav__spacer"></span>
			<?php endif; ?>

			<?php if ( $next_url && $next_is_locked ) : ?>
				<button type="button"
						class="lrn-lesson-nav__next is-locked"
						disabled
						aria-disabled="true"
						title="<?php esc_attr_e( 'Complete this lesson first.', 'learnomy' ); ?>">
					<span>
						<small><?php esc_html_e( 'Next', 'learnomy' ); ?></small>
						<strong><?php echo esc_html( $next_lesson->title ); ?></strong>
					</span>
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
				</button>
			<?php elseif ( $next_url ) : ?>
				<a href="<?php echo esc_url( $next_url ); ?>" class="lrn-lesson-nav__next">
					<span>
						<small><?php esc_html_e( 'Next', 'learnomy' ); ?></small>
						<strong><?php echo esc_html( $next_lesson->title ); ?></strong>
					</span>
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
				</a>
			<?php elseif ( $next_is_locked ) : ?>
				<button type="button"
						class="lrn-lesson-nav__next is-locked"
						disabled
						aria-disabled="true"
						title="<?php esc_attr_e( 'Complete this lesson first.', 'learnomy' ); ?>">
					<span>
						<small><?php esc_html_e( 'Finished', 'learnomy' ); ?></small>
						<?php esc_html_e( 'Back to Course', 'learnomy' ); ?>
					</span>
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
				</button>
			<?php else : ?>
				<a href="<?php echo esc_url( \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) ) ); ?>" class="lrn-lesson-nav__next">
					<span>
						<small><?php esc_html_e( 'Finished', 'learnomy' ); ?></small>
						<?php esc_html_e( 'Back to Course', 'learnomy' ); ?>
					</span>
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
				</a>
			<?php endif; ?>
		</nav>

	</main>

	<?php
	// Lesson comments / Q&A — visible to enrolled students AND to the people who
	// manage the course (its instructor + admins), who aren't "enrolled" but
	// must be able to read and answer the discussion they were linked to from
	// the Q&A inbox (Basecamp #9941294678).
	//
	// Architecture rule: templates have zero DB queries; all data comes from
	// Single_Lesson_View_Data::resolve() which pre-fetches $comment_tree,
	// $total_comments, and $instructor_ids. The comment queries that used to
	// live here duplicated the view-data work on every page load — removed.
	if ( isset( $comments_enabled ) && $comments_enabled && ( $is_enrolled || ! empty( $is_instructor ) || ! empty( $is_admin ) ) ) :
		// $comment_tree, $total_comments, and $instructor_ids are pre-resolved
		// by Single_Lesson_View_Data and injected into this template scope by
		// Template_Loader — do not re-query here.
		$comment_tree   = isset( $comment_tree ) && is_array( $comment_tree ) ? $comment_tree : array();
		$total_comments = isset( $total_comments ) ? (int) $total_comments : 0;
		// $instructor_ids already carries co-instructor IDs from view-data;
		// no need to call Course::get_instructors() again.
		$instructor_ids = isset( $instructor_ids ) && is_array( $instructor_ids ) ? $instructor_ids : array( (int) $course->author_id );
		?>
	<section class="lrn-lesson-comments" id="lrn-comments"
		data-wp-interactive="learnomy/comments"
		data-wp-context='{"lessonId":<?php echo (int) $lesson->id; ?>,"courseId":<?php echo (int) $course->id; ?>}'>

		<?php
		// Strings the comments module needs, as a JSON element co-located with
		// the markup. The module reads this directly — wp_localize_script /
		// wp_add_inline_script don't reliably reach a script MODULE (the
		// classic-script data handle was orphaned, leaving learnomyComments
		// undefined, which made the post-success handler throw and silently
		// drop the new comment — so a retry posted duplicates). A DOM element
		// is the module-safe transport (matches the frontend builder).
		?>
		<script type="application/json" id="learnomy-comments-data">
			<?php
			echo wp_json_encode(
				array(
					'i18n' => array(
						'confirmDelete'   => __( 'Are you sure you want to delete this comment?', 'learnomy' ),
						'instructor'      => \Learnomy\instructor_label(),
						'deleteLabel'     => __( 'Delete', 'learnomy' ),
						'posted'          => __( 'Comment posted.', 'learnomy' ),
						'pendingNotice'   => __( 'Your comment is awaiting review. It will appear once it is approved.', 'learnomy' ),
						'postFailed'      => __( 'Could not post your comment. Please try again.', 'learnomy' ),
						/* translators: %d: number of comments. */
						'commentSingular' => __( 'Discussion (%d comment)', 'learnomy' ),
						/* translators: %d: number of comments. */
						'commentPlural'   => __( 'Discussion (%d comments)', 'learnomy' ),
					),
				)
			);
			?>
		</script>

		<h3 class="lrn-lesson-comments__heading">
			<?php
			/* translators: %d: number of comments */
			echo esc_html( sprintf( _n( 'Discussion (%d comment)', 'Discussion (%d comments)', $total_comments, 'learnomy' ), $total_comments ) );
			?>
		</h3>

		<!-- Comment form -->
		<form class="lrn-comment-form" data-wp-on--submit="actions.submitComment">
			<div class="lrn-comment-form__row">
				<?php echo get_avatar( $user_id, 36, '', '', array( 'class' => 'lrn-comment-form__avatar' ) ); ?>
				<textarea
					class="lrn-comment-form__textarea"
					name="comment_text"
					rows="3"
					placeholder="<?php esc_attr_e( 'Ask a question or share your thoughts…', 'learnomy' ); ?>"
					required
				></textarea>
			</div>
			<div class="lrn-comment-form__actions">
				<button type="submit" class="lrn-btn lrn-btn--primary lrn-btn--sm">
					<?php esc_html_e( 'Post Comment', 'learnomy' ); ?>
				</button>
			</div>
		</form>

		<!-- Comments list -->
		<div class="lrn-comments-list" id="lrn-comments-list">
			<?php if ( empty( $comment_tree ) ) : ?>
				<p class="lrn-comments-list__empty">
					<?php esc_html_e( 'No comments yet. Be the first to start the discussion.', 'learnomy' ); ?>
				</p>
			<?php endif; ?>

			<?php
			foreach ( $comment_tree as $entry ) :
				$c            = $entry['comment'];
				$c_author     = get_user_by( 'ID', $c->user_id );
				$c_is_instr   = in_array( (int) $c->user_id, $instructor_ids, true );
				$c_time_ago   = human_time_diff( strtotime( $c->comment_date_gmt ), time() );
				$c_can_delete = ( (int) $c->user_id === $user_id ) || current_user_can( 'manage_options' );
				?>
				<div class="lrn-comment" id="lrn-comment-<?php echo (int) $c->comment_ID; ?>"
					data-comment-id="<?php echo (int) $c->comment_ID; ?>"
					data-author-id="<?php echo (int) $c->user_id; ?>">
					<?php echo get_avatar( $c->user_id, 36, '', '', array( 'class' => 'lrn-comment__avatar' ) ); ?>
					<div class="lrn-comment__body">
						<div class="lrn-comment__header">
							<span class="lrn-comment__author"><?php echo esc_html( $c_author ? $c_author->display_name : __( 'Anonymous', 'learnomy' ) ); ?></span>
							<?php if ( $c_is_instr ) : ?>
								<span class="lrn-comment__badge"><?php echo esc_html( \Learnomy\instructor_label() ); ?></span>
							<?php endif; ?>
							<time class="lrn-comment__date" datetime="<?php echo esc_attr( $c->comment_date_gmt ); ?>">
								<?php
								/* translators: %s: human-readable time difference */
								echo esc_html( sprintf( __( '%s ago', 'learnomy' ), $c_time_ago ) );
								?>
							</time>
						</div>
						<p class="lrn-comment__text"><?php echo esc_html( $c->comment_content ); ?></p>
						<div class="lrn-comment__actions">
							<button type="button" class="lrn-comment__reply-btn" data-wp-on--click="actions.toggleReplyForm">
								<?php esc_html_e( 'Reply', 'learnomy' ); ?>
							</button>
							<?php if ( $c_can_delete ) : ?>
								<button type="button" class="lrn-comment__delete-btn"
									data-wp-on--click="actions.deleteComment"
									data-comment-id="<?php echo (int) $c->comment_ID; ?>">
									<?php esc_html_e( 'Delete', 'learnomy' ); ?>
								</button>
							<?php endif; ?>
							<?php if ( $user_id > 0 && (int) $c->user_id !== $user_id ) : ?>
								<?php
								\Learnomy\report_button(
									'comment',
									(int) $c->comment_ID,
									array(
										'class' => 'lrn-comment__report-btn',
										'icon'  => false,
									)
								);
								?>
								<?php // Block this member (hide their comments + reviews). Staff are unblockable. ?>
								<?php if ( ! user_can( (int) $c->user_id, 'manage_options' ) ) : ?>
									<button type="button" class="lrn-block-btn lrn-comment__block-btn"
										data-user-id="<?php echo (int) $c->user_id; ?>"
										data-user-name="<?php echo esc_attr( $c_author ? $c_author->display_name : '' ); ?>">
										<span class="lrn-block-btn__label"><?php esc_html_e( 'Block', 'learnomy' ); ?></span>
									</button>
								<?php endif; ?>
							<?php endif; ?>
						</div>

						<!-- Inline reply form (hidden by default via CSS .lrn-comment-form--reply[hidden]) -->
						<form class="lrn-comment-form lrn-comment-form--reply" hidden
							data-wp-on--submit="actions.submitReply"
							data-parent-id="<?php echo (int) $c->comment_ID; ?>">
							<textarea
								class="lrn-comment-form__textarea"
								name="reply_text"
								rows="2"
								placeholder="<?php esc_attr_e( 'Write a reply…', 'learnomy' ); ?>"
								required
							></textarea>
							<div class="lrn-comment-form__actions">
								<button type="button" class="lrn-btn lrn-btn--ghost lrn-btn--sm" data-wp-on--click="actions.toggleReplyForm">
									<?php esc_html_e( 'Cancel', 'learnomy' ); ?>
								</button>
								<button type="submit" class="lrn-btn lrn-btn--primary lrn-btn--sm">
									<?php esc_html_e( 'Reply', 'learnomy' ); ?>
								</button>
							</div>
						</form>

						<!-- Replies -->
						<?php if ( ! empty( $entry['replies'] ) ) : ?>
							<div class="lrn-comment__replies">
								<?php
								foreach ( $entry['replies'] as $reply ) :
									$r_author     = get_user_by( 'ID', $reply->user_id );
									$r_is_instr   = in_array( (int) $reply->user_id, $instructor_ids, true );
									$r_time_ago   = human_time_diff( strtotime( $reply->comment_date_gmt ), time() );
									$r_can_delete = ( (int) $reply->user_id === $user_id ) || current_user_can( 'manage_options' );
									?>
									<div class="lrn-comment lrn-comment--reply" id="lrn-comment-<?php echo (int) $reply->comment_ID; ?>"
										data-comment-id="<?php echo (int) $reply->comment_ID; ?>">
										<?php echo get_avatar( $reply->user_id, 28, '', '', array( 'class' => 'lrn-comment__avatar' ) ); ?>
										<div class="lrn-comment__body">
											<div class="lrn-comment__header">
												<span class="lrn-comment__author"><?php echo esc_html( $r_author ? $r_author->display_name : __( 'Anonymous', 'learnomy' ) ); ?></span>
												<?php if ( $r_is_instr ) : ?>
													<span class="lrn-comment__badge"><?php echo esc_html( \Learnomy\instructor_label() ); ?></span>
												<?php endif; ?>
												<time class="lrn-comment__date" datetime="<?php echo esc_attr( $reply->comment_date_gmt ); ?>">
													<?php
													/* translators: %s: human-readable time difference */
													echo esc_html( sprintf( __( '%s ago', 'learnomy' ), $r_time_ago ) );
													?>
												</time>
											</div>
											<p class="lrn-comment__text"><?php echo esc_html( $reply->comment_content ); ?></p>
											<?php if ( $r_can_delete || ( $user_id > 0 && (int) $reply->user_id !== $user_id ) ) : ?>
												<div class="lrn-comment__actions">
													<?php if ( $r_can_delete ) : ?>
														<button type="button" class="lrn-comment__delete-btn"
															data-wp-on--click="actions.deleteComment"
															data-comment-id="<?php echo (int) $reply->comment_ID; ?>">
															<?php esc_html_e( 'Delete', 'learnomy' ); ?>
														</button>
													<?php endif; ?>
													<?php if ( $user_id > 0 && (int) $reply->user_id !== $user_id ) : ?>
														<?php
														\Learnomy\report_button(
															'comment',
															(int) $reply->comment_ID,
															array(
																'class' => 'lrn-comment__report-btn',
																'icon'  => false,
															)
														);
														?>
													<?php endif; ?>
												</div>
											<?php endif; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>
</div>
