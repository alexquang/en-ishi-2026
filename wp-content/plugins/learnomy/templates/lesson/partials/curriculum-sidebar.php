<?php
/**
 * Lesson player — collapsible curriculum sidebar + course progress bar.
 *
 * Shared by both lesson layout variants (focus, embedded). Renders the
 * collapsible curriculum navigation (sections → lessons → topics, with
 * completed / current / locked states) and the course progress bar footer.
 *
 * Included from templates/lesson/focus.php and templates/lesson/embedded.php
 * via `include`, so it inherits the including template's variable scope:
 *   $course, $lesson, $user_id, $sections, $section_lessons_map,
 *   $section_quizzes_map, $completed_ids, $current_section_id, $all_lessons,
 *   $locked_lesson_ids, $progress_pct.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// Bare `Lesson::get_topics()` below resolves via this import — `use`
// statements do not carry over from the including template into a partial.
use Learnomy\Models\Lesson;

// One quiz row for the player nav, in the same shape as a lesson row so it
// inherits the sidebar's spacing, hover and active styling. $nested indents it
// to the topic rail, marking it as belonging to the lesson above it.
$lrn_render_player_quiz = static function ( $quiz, bool $nested, bool $locked = false ) use ( $course ): void {
	$quiz_url = \Learnomy\route_url(
		'quiz',
		array(
			'slug'      => (string) $course->slug,
			'quiz_slug' => (string) ( $quiz->slug ?? '' ),
		)
	);
	$classes = 'lrn-player-item lrn-player-item--quiz' . ( $nested ? ' lrn-player-item--sub' : '' ) . ( $locked ? ' lrn-player-item--locked' : '' );
	?>
	<li class="<?php echo esc_attr( $classes ); ?>">
		<?php if ( $locked ) : ?>
			<svg class="lrn-player-item__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
		<?php else : ?>
			<svg class="lrn-player-item__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></svg>
		<?php endif; ?>
		<?php if ( $locked ) : ?>
			<span class="lrn-player-item__label is-locked" aria-disabled="true" title="<?php esc_attr_e( 'This quiz unlocks with its lesson.', 'learnomy' ); ?>"><?php echo esc_html( $quiz->title ); ?></span>
		<?php else : ?>
			<a href="<?php echo esc_url( $quiz_url ); ?>" class="lrn-player-item__label"><?php echo esc_html( $quiz->title ); ?></a>
		<?php endif; ?>
		<span class="lrn-player-item__badge"><?php echo esc_html( \Learnomy\quiz_label() ); ?></span>
	</li>
	<?php
};
?>
	<!-- Collapsible sidebar -->
	<?php
	/*
	 * Both polarities are bound on purpose.
	 *
	 * `--collapsed` drives the desktop rail, which is open by default and closes
	 * on the class. `--open` drives the mobile drawer, which is CLOSED by default
	 * in CSS and opens on the class. Binding only `--collapsed` is what made the
	 * drawer paint open across the lesson on every load under 1024px until the
	 * Interactivity module hydrated (Basecamp 10167960085): the server cannot
	 * resolve a directive in a template, so the class is simply absent in the
	 * delivered HTML and the fail-safe default was "open".
	 *
	 * Both read the one store value, so they cannot disagree.
	 */
	?>
	<aside class="lrn-player-sidebar"
		data-wp-class--lrn-player-sidebar--collapsed="state.sidebarCollapsed"
		data-wp-class--lrn-player-sidebar--open="state.sidebarOpen">
		<div class="lrn-player-sidebar__header">
			<a href="<?php echo esc_url( \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) ) ); ?>"
				class="lrn-player-sidebar__title">
				<?php echo esc_html( $course->title ); ?>
			</a>
			<?php
			$action_icon  = 'panel-left-close';
			$action_label = null;
			$action_title = null;
			$action_href  = null;
			$action_aria  = __( 'Collapse sidebar', 'learnomy' );
			$action_class = 'lrn-player-sidebar__toggle';
			$action_attrs = [
				'data-wp-on--click' => 'actions.toggleSidebar',
			];
			require \Learnomy\template_path( 'partials/lrn-action.php' );

			/**
			 * Top of the lesson curriculum sidebar (e.g. resume CTA, course meta).
			 *
			 * Fires with no listeners by default, so this is a no-op (zero output)
			 * in the FOCUS variant — it keeps that variant byte-identical while
			 * giving the EMBEDDED variant + Pro a placement region.
			 *
			 * @param object $lesson Current lesson row.
			 * @param object $course Parent course row.
			 */
			do_action( 'learnomy_lesson_sidebar_top', $lesson, $course );
			?>
		</div>

		<nav class="lrn-player-sidebar__nav" aria-label="<?php esc_attr_e( 'Course curriculum', 'learnomy' ); ?>">
			<?php if ( ! empty( $sections ) ) : ?>
				<?php
				// Surface section-less lessons under a leading "Ungrouped"
				// pseudo-section (id=0, populated in the view-data) so they reach
				// students even when other lessons are grouped. Local copy — the
				// shared $sections is left untouched (Basecamp #10009374408).
				$lrn_nav_sections = $sections;
				if ( ! empty( $section_lessons_map[0] ) ) {
					array_unshift(
						$lrn_nav_sections,
						(object) array(
							'id'    => 0,
							'title' => __( 'Ungrouped', 'learnomy' ),
						)
					);
				}
				foreach ( $lrn_nav_sections as $section ) :
					$section_lessons = $section_lessons_map[ (int) $section->id ] ?? array();
					$section_total   = count( $section_lessons );
					$section_done    = 0;
					$lrn_nav_lesson_ids = array();
					foreach ( $section_lessons as $sl ) {
						$lrn_nav_lesson_ids[] = (int) $sl->id;
						if ( isset( $completed_ids[ (int) $sl->id ] ) ) {
							++$section_done;
						}
					}

					// Same split the course page runs, off the same model helper, so
					// a quiz cannot sit under its lesson on one screen and at the end
					// of the module on the other.
					$lrn_nav_quiz_groups          = \Learnomy\Models\Quiz::split_by_lesson(
						$section_quizzes_map[ (int) $section->id ] ?? array(),
						$lrn_nav_lesson_ids
					);
					$lrn_sidebar_lesson_quizzes   = $lrn_nav_quiz_groups['by_lesson'];
					$lrn_sidebar_section_quizzes  = $lrn_nav_quiz_groups['section_end'];
					$is_current_section = ( (int) $section->id === $current_section_id );
					?>
					<div class="lrn-player-section<?php echo esc_attr( $is_current_section ? ' lrn-player-section--active' : '' ); ?>">
						<button type="button"
								class="lrn-player-section__header"
								data-wp-on--click="actions.toggleSection"
								aria-expanded="<?php echo esc_attr( $is_current_section ? 'true' : 'false' ); ?>">
							<span class="lrn-player-section__name"><?php echo esc_html( $section->title ); ?></span>
							<?php if ( $user_id && $section_total > 0 ) : ?>
								<span class="lrn-player-section__progress"><?php echo esc_html( $section_done . '/' . $section_total ); ?></span>
							<?php else : ?>
								<span class="lrn-player-section__progress">
									<?php
									/* translators: %d: number of lessons in the section */
									echo esc_html( sprintf( _n( '%d lesson', '%d lessons', $section_total, 'learnomy' ), $section_total ) );
									?>
								</span>
							<?php endif; ?>
						</button>
						<ul class="lrn-player-section__items" <?php echo esc_attr( ! $is_current_section ? 'hidden' : '' ); ?>>
							<?php
							foreach ( $section_lessons as $sl ) :
								$sl_url     = \Learnomy\route_url(
									'lesson',
									array(
										'slug'        => (string) $course->slug,
										'lesson_slug' => (string) $sl->slug,
									)
								);
								$sl_done    = isset( $completed_ids[ (int) $sl->id ] );
								$sl_current = ( (int) $sl->id === (int) $lesson->id );
								// Lock when the require-sequential cursor blocks the row OR when
								// the drip schedule has not yet released it. The current lesson
								// row never renders as locked — that state is handled by the
								// main content area (the locked partial swap above).
								$sl_drip   = ! empty( $sl->drip_locked );
								$sl_locked = ( isset( $locked_lesson_ids[ (int) $sl->id ] ) || $sl_drip ) && ! $sl_current;

								// "Completed" and "current" are not alternatives — the row a
								// student marks complete is BOTH, and the moment they hit the
								// button it is the most common combination on the page. The
								// old if/elseif dropped --completed from that row, so the
								// check icon (chosen a few lines down, which DID test done
								// first) rendered in the current-row accent instead of the
								// completed green, and anything keyed on --completed — theme
								// overrides, Pro, tests — saw the lesson as not done
								// (Basecamp 10134140321). Only --locked is genuinely
								// exclusive: a finished lesson is never locked.
								$item_class = 'lrn-player-item';
								if ( $sl_done ) {
									$item_class .= ' lrn-player-item--completed';
								}
								if ( $sl_current ) {
									$item_class .= ' lrn-player-item--current';
								}
								if ( $sl_locked && ! $sl_done ) {
									$item_class .= ' lrn-player-item--locked';
								}
								?>
								<li class="<?php echo esc_attr( $item_class ); ?>">
									<?php if ( $sl_done ) : ?>
										<svg class="lrn-player-item__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
									<?php elseif ( $sl_current ) : ?>
										<svg class="lrn-player-item__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
									<?php elseif ( $sl_locked ) : ?>
										<svg class="lrn-player-item__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
									<?php else : ?>
										<svg class="lrn-player-item__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/></svg>
									<?php endif; ?>
									<?php if ( $sl_current ) : ?>
										<span class="lrn-player-item__label"><?php echo esc_html( $sl->title ); ?></span>
									<?php elseif ( $sl_locked ) : ?>
										<span class="lrn-player-item__label is-locked" aria-disabled="true" title="<?php esc_attr_e( 'Complete the previous lessons to unlock this one.', 'learnomy' ); ?>"><?php echo esc_html( $sl->title ); ?></span>
									<?php else : ?>
										<a href="<?php echo esc_url( $sl_url ); ?>" class="lrn-player-item__label"><?php echo esc_html( $sl->title ); ?></a>
									<?php endif; ?>
									<?php if ( ! empty( $sl->duration ) ) : ?>
										<span class="lrn-player-item__duration"><?php echo esc_html( $sl->duration ); ?></span>
									<?php endif; ?>
								</li>
								<?php
								// Sub-lessons (topics) nested under parent lesson.
								// `Lesson` resolves via `use Learnomy\Models\Lesson;` at
								// the top of this file. Without the use statement the
								// bare reference fatals.
								$topics = Lesson::get_topics( (int) $sl->id );
								if ( $topics ) :
									foreach ( $topics as $topic ) :
										$tp_url     = \Learnomy\route_url(
											'lesson',
											array(
												'slug' => (string) $course->slug,
												'lesson_slug' => (string) $topic->slug,
											)
										);
										$tp_done    = isset( $completed_ids[ (int) $topic->id ] );
										$tp_current = ( (int) $topic->id === (int) $lesson->id );
										$tp_drip    = ! empty( $topic->drip_locked );
										$tp_locked  = ( isset( $locked_lesson_ids[ (int) $topic->id ] ) || $tp_drip ) && ! $tp_current;
										// Additive states — see the parent-row block above.
										$tp_class = 'lrn-player-item lrn-player-item--sub';
										if ( $tp_done ) {
											$tp_class .= ' lrn-player-item--completed';
										}
										if ( $tp_current ) {
											$tp_class .= ' lrn-player-item--current';
										}
										if ( $tp_locked && ! $tp_done ) {
											$tp_class .= ' lrn-player-item--locked';
										}
										?>
									<li class="<?php echo esc_attr( $tp_class ); ?>">
										<?php if ( $tp_done ) : ?>
											<svg class="lrn-player-item__icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
										<?php elseif ( $tp_current ) : ?>
											<svg class="lrn-player-item__icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
										<?php elseif ( $tp_locked ) : ?>
											<svg class="lrn-player-item__icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
										<?php else : ?>
											<svg class="lrn-player-item__icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/></svg>
										<?php endif; ?>
										<?php if ( $tp_current ) : ?>
											<span class="lrn-player-item__label"><?php echo esc_html( $topic->title ); ?></span>
										<?php elseif ( $tp_locked ) : ?>
											<span class="lrn-player-item__label is-locked" aria-disabled="true" title="<?php esc_attr_e( 'Complete the previous lessons to unlock this one.', 'learnomy' ); ?>"><?php echo esc_html( $topic->title ); ?></span>
										<?php else : ?>
											<a href="<?php echo esc_url( $tp_url ); ?>" class="lrn-player-item__label"><?php echo esc_html( $topic->title ); ?></a>
										<?php endif; ?>
									</li>
										<?php
								endforeach;
endif;
								?>
								<?php
								// This lesson's quizzes, directly under it — the same
								// placement the course page uses, so a student sees the
								// course described identically on both screens.
								foreach ( $lrn_sidebar_lesson_quizzes[ (int) $sl->id ] ?? array() as $lrn_sq ) {
									$lrn_render_player_quiz( $lrn_sq, true, $sl_locked );
								}
								?>
							<?php endforeach; ?>
							<?php
							// Quizzes the author left on "End of section" close the
							// section, after every lesson in it.
							foreach ( $lrn_sidebar_section_quizzes as $lrn_sq ) {
								$lrn_render_player_quiz( $lrn_sq, false );
							}
							?>
						</ul>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<?php // No sections — flat lesson list. ?>
				<ul class="lrn-player-section__items lrn-player-section__items--flat">
					<?php
					foreach ( $all_lessons as $al ) :
						$al_url     = \Learnomy\route_url(
							'lesson',
							array(
								'slug'        => (string) $course->slug,
								'lesson_slug' => (string) $al->slug,
							)
						);
						$al_done    = isset( $completed_ids[ (int) $al->id ] );
						$al_current = ( (int) $al->id === (int) $lesson->id );
						$al_drip    = ! empty( $al->drip_locked );
						$al_locked  = ( isset( $locked_lesson_ids[ (int) $al->id ] ) || $al_drip ) && ! $al_current;

						// Additive states — see the sectioned block above.
						$item_class = 'lrn-player-item';
						if ( $al_done ) {
							$item_class .= ' lrn-player-item--completed';
						}
						if ( $al_current ) {
							$item_class .= ' lrn-player-item--current';
						}
						if ( $al_locked && ! $al_done ) {
							$item_class .= ' lrn-player-item--locked';
						}
						?>
						<li class="<?php echo esc_attr( $item_class ); ?>">
							<?php if ( $al_done ) : ?>
								<svg class="lrn-player-item__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
							<?php elseif ( $al_current ) : ?>
								<svg class="lrn-player-item__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
							<?php elseif ( $al_locked ) : ?>
								<svg class="lrn-player-item__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
							<?php else : ?>
								<svg class="lrn-player-item__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/></svg>
							<?php endif; ?>
							<?php if ( $al_current ) : ?>
								<span class="lrn-player-item__label"><?php echo esc_html( $al->title ); ?></span>
							<?php elseif ( $al_locked ) : ?>
								<span class="lrn-player-item__label is-locked" aria-disabled="true" title="<?php esc_attr_e( 'Complete the previous lessons to unlock this one.', 'learnomy' ); ?>"><?php echo esc_html( $al->title ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( $al_url ); ?>" class="lrn-player-item__label"><?php echo esc_html( $al->title ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</nav>

		<!-- Course progress at bottom: a clear "Course progress  NN%" row above
			the bar so the completion percentage is plainly visible, not a faint
			micro-label (Basecamp #9972731668).

			Only for a viewer who HAS an enrollment to make progress against.
			A free-preview lesson is a marketing page — it is reachable with no
			account at all, and a "Course progress 0%" bar there described a
			tracker that was not tracking anything, to someone who had nothing to
			track (Basecamp 10143833815). The same space says what that visitor
			actually needs next instead. Instructors and admins previewing are
			not enrolled either; their progress bar was equally meaningless. -->
		<div class="lrn-player-sidebar__footer">
			<?php if ( ! empty( $is_enrolled ) ) : ?>
			<div class="lrn-player-sidebar__progress-head">
				<span class="lrn-player-sidebar__progress-label"><?php esc_html_e( 'Course progress', 'learnomy' ); ?></span>
				<?php /* translators: %d: progress percentage */ ?>
				<span class="lrn-player-sidebar__progress-pct"><?php echo esc_html( sprintf( __( '%d%%', 'learnomy' ), (int) $progress_pct ) ); ?></span>
			</div>
			<div class="lrn-progress-bar">
				<div class="lrn-progress-bar__fill" style="width: <?php echo esc_attr( $progress_pct ); ?>%"></div>
			</div>
			<?php else : ?>
			<p class="lrn-player-sidebar__progress-label">
				<a href="<?php echo esc_url( \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) ) ); ?>">
					<?php esc_html_e( 'Enroll to track your progress', 'learnomy' ); ?>
				</a>
			</p>
			<?php endif; ?>
			<?php
			/**
			 * Bottom of the lesson curriculum sidebar (e.g. instructor card,
			 * badges). No listeners by default, so this is a no-op (zero output)
			 * in the FOCUS variant while giving the EMBEDDED variant + Pro a
			 * placement region.
			 *
			 * @param object $lesson Current lesson row.
			 * @param object $course Parent course row.
			 */
			do_action( 'learnomy_lesson_sidebar_bottom', $lesson, $course );
			?>
		</div>
	</aside>
