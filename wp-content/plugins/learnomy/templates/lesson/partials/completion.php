<?php
/**
 * Lesson completion block — completion-requirements strip + Mark Complete
 * button + the shared progress toast.
 *
 * Extracted verbatim from partials/player-content.php so BOTH the collapsible
 * player (focus) AND the new sidebar embedded layout render the exact same
 * completion markup — preserving the `learnomy/progress` Interactivity store
 * bindings (`data-wp-bind--*`, `actions.completeLesson`) so mark-complete and
 * the requirements bar keep working unchanged.
 *
 * Included via `include` from partials/player-content.php and from
 * templates/lesson/embedded.php, inheriting the including scope:
 *   $is_enrolled, $strip_visible, $is_completed, $completion_all_met,
 *   $ui_requirements, $require_watch_pct, $lesson. The caller MUST have run
 *   the completion-status hydration (the variant template does) before the
 *   include, and must be inside a `data-wp-interactive="learnomy/progress"`
 *   element seeded with $progress_context.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;
?>
		<!-- Lesson actions -->
		<div class="lrn-player-content__actions">
			<?php if ( $is_enrolled ) : ?>
				<?php if ( $strip_visible && ! $is_completed ) : ?>
					<div
						class="lrn-completion-strip"
						role="group"
						aria-label="<?php esc_attr_e( 'Lesson completion requirements', 'learnomy' ); ?>"
						data-wp-bind--hidden="state.isManual"
					>
						<div class="lrn-completion-strip__header">
							<?php
							$lrn_enforcement_mode = $completion_enforcement ?? 'strict';
							if ( 'advisory' === $lrn_enforcement_mode ) :
								?>
								<span class="lrn-completion-strip__status">
									<?php esc_html_e( 'These steps are recommended. You can mark this lesson complete anytime.', 'learnomy' ); ?>
								</span>
							<?php elseif ( $completion_all_met ) : ?>
								<span class="lrn-completion-strip__status lrn-completion-strip__status--met">
									<?php esc_html_e( 'All requirements met. You can mark this lesson complete.', 'learnomy' ); ?>
								</span>
							<?php else : ?>
								<span class="lrn-completion-strip__status">
									<?php esc_html_e( 'Complete the following to unlock the next lesson:', 'learnomy' ); ?>
								</span>
							<?php endif; ?>
						</div>
						<ul class="lrn-completion-strip__list">
							<?php foreach ( $ui_requirements as $req ) : ?>
								<li class="lrn-completion-strip__line<?php echo ! empty( $req['met'] ) ? ' lrn-completion-strip__line--met' : ''; ?>"
									data-req-kind="<?php echo esc_attr( $req['kind'] ?? '' ); ?>">
									<span class="lrn-completion-strip__icon" aria-hidden="true">
										<?php if ( ! empty( $req['met'] ) ) : ?>
											<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
										<?php else : ?>
											<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
										<?php endif; ?>
									</span>
									<span class="lrn-completion-strip__text"><?php echo esc_html( $req['label'] ?? '' ); ?></span>
									<?php if ( 'time' === ( $req['kind'] ?? '' ) && ! empty( $req['min_seconds'] ) && empty( $req['met'] ) ) : ?>
										<span class="lrn-completion-strip__remaining" data-req-remaining>
											<?php
											$remaining = max( 0, (int) ( $req['remaining'] ?? $req['min_seconds'] ) );
											echo esc_html(
												sprintf(
												/* translators: %s: remaining time formatted as mm:ss */
													__( '%s left', 'learnomy' ),
													sprintf( '%d:%02d', intdiv( $remaining, 60 ), $remaining % 60 )
												)
											);
											?>
										</span>
									<?php endif; ?>
									<progress class="lrn-completion-strip__bar" max="100" value="<?php echo (int) ( $req['uiPercent'] ?? 0 ); ?>"></progress>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( $is_completed ) : ?>
					<span class="lrn-badge lrn-badge--green"><?php esc_html_e( 'Completed', 'learnomy' ); ?></span>
				<?php else : ?>
					<?php
					$action_icon     = 'check-circle';
					$action_label    = __( 'Complete', 'learnomy' );
					$action_title    = null;
					$action_href     = null;
					$action_aria     = __( 'Mark lesson complete', 'learnomy' );
					$action_variant  = 'primary';
					$action_class    = 'lrn-mark-complete';
					$action_disabled = ! $completion_all_met;
					$action_attrs    = [
						'id'                     => 'lrn-mark-complete',
						'data-wp-on--click'      => 'actions.completeLesson',
						'data-wp-bind--disabled' => 'state.completeDisabled',
					];
					if ( $require_watch_pct > 0 && ! empty( $lesson->video_url ) ) {
						$action_attrs['data-require-watch'] = (int) $require_watch_pct;
					}
					include \Learnomy\template_path( 'partials/lrn-action.php' );
					$action_variant  = null;
					$action_disabled = false;
					?>
				<?php endif; ?>
			<?php endif; ?>

			<!-- Toast — shared across progress actions (complete/topic/requirements).
				Hidden by default (no CSS rule for `.lrn-toast--visible` in the
				frontend bundle) so the empty bordered placeholder can't leak
				through below the Editor.js body. Interactivity flips `hidden`
				on via state.toastVisible when a message is queued. -->
			<div
				class="lrn-toast lrn-toast--info"
				role="status"
				aria-live="polite"
				hidden
				data-wp-bind--hidden="!state.toastVisible"
				data-wp-class--lrn-toast--visible="state.toastVisible"
				data-wp-text="state.toastMessage"
			></div>
		</div>
