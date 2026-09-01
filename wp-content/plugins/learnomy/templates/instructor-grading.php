<?php
/**
 * Instructor Grading — /instructor/grading/
 *
 * The teaching shell's answer to "a teacher should never have to open wp-admin
 * to mark an essay". Two modes from one route, mirroring the wp-admin screen:
 *
 *   Queue  — attempts awaiting a manual grade (default) or attempts already
 *            graded (?status=graded), paginated, filterable by course.
 *   Grader — ?attempt=N: the student's answer per question, with a score input
 *            bounded by the question's marks and an optional feedback box.
 *            Saves through POST learnomy/v1/attempts/{id}/grade — the same
 *            route the wp-admin grader and the mobile app use.
 *
 * View-data (Instructor_Grading_View_Data): $mode, $status, $rows, $total,
 * $page, $per_page, $course_id, $filter_courses, $base_url, $pending_url,
 * $graded_url, $attempt_id, $quiz_title, $student_name, $submitted_at,
 * $pending_count, and the integrity signals.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$mode           = isset( $mode ) ? (string) $mode : 'queue';
$status         = isset( $status ) ? (string) $status : 'pending';
$rows           = isset( $rows ) && is_array( $rows ) ? $rows : array();
$total          = isset( $total ) ? (int) $total : 0;
$page           = isset( $page ) ? max( 1, (int) $page ) : 1;
$per_page       = isset( $per_page ) ? max( 1, (int) $per_page ) : 20;
$course_id      = isset( $course_id ) ? (int) $course_id : 0;
$filter_courses = isset( $filter_courses ) && is_array( $filter_courses ) ? $filter_courses : array();
$base_url       = isset( $base_url ) ? (string) $base_url : \Learnomy\route_url( 'instructor-grading' );
$is_graded_view = 'graded' === $status;
$date_format    = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
?>
<main class="lrn-container lrn-dashboard lrn-instructor-section" id="lrn-instructor-grading">

	<?php
	$breadcrumb_items = array(
		array(
			'label' => sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s Dashboard', 'learnomy' ), \Learnomy\instructor_label() ),
			'href'  => \Learnomy\route_url( 'instructor-dashboard' ),
			'icon'  => 'layout-dashboard',
		),
		array( 'label' => __( 'Grading', 'learnomy' ) ),
	);
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<div class="lrn-account-layout">
		<?php
		$instructor_nav_active = 'instructor-grading';
		require \Learnomy\template_path( 'partials/instructor-nav.php' );
		?>
		<div class="lrn-account-layout__content">

			<?php if ( 'grader' === $mode ) : ?>

				<p class="lrn-account-back">
					<a href="<?php echo esc_url( $base_url ); ?>">&larr; <?php esc_html_e( 'Back to grading', 'learnomy' ); ?></a>
				</p>

				<?php
				$account_page = array(
					'icon'     => 'clipboard-check',
					'title'    => __( 'Grade attempt', 'learnomy' ),
					'subtitle' => trim(
						sprintf(
							/* translators: 1: student name, 2: quiz title */
							__( '%1$s, %2$s', 'learnomy' ),
							(string) $student_name,
							(string) $quiz_title
						)
					),
				);
				require \Learnomy\template_path( 'partials/account-page-header.php' );
				?>

				<?php
				$lrn_signals = array();
				if ( '' !== (string) $ip_address ) {
					/* translators: %s: IP address the attempt was submitted from */
					$lrn_signals[] = array(
						'icon' => 'map-pin',
						'flag' => false,
						'text' => sprintf( __( 'IP: %s', 'learnomy' ), (string) $ip_address ),
					);
				}
				if ( ! empty( $tab_tracking ) ) {
					$lrn_signals[] = array(
						'icon' => ! empty( $tab_flagged ) ? 'shield-alert' : 'shield-check',
						'flag' => ! empty( $tab_flagged ),
						/* translators: %d: number of times the learner left the quiz tab */
						'text' => sprintf( __( 'Tab switches: %d', 'learnomy' ), (int) $tab_switches ),
					);
				}
				if ( ! empty( $exam_late ) ) {
					$lrn_signals[] = array(
						'icon' => 'timer-off',
						'flag' => true,
						'text' => (int) $exam_late_minutes > 0
							/* translators: %d: minutes past the time limit */
							? sprintf( _n( 'Submitted %d min after the time limit', 'Submitted %d min after the time limit', (int) $exam_late_minutes, 'learnomy' ), (int) $exam_late_minutes )
							: __( 'Submitted after the time limit', 'learnomy' ),
					);
				}
				?>

				<?php if ( ! empty( $lrn_signals ) || '' !== (string) $submitted_at ) : ?>
					<p class="lrn-grading-meta">
						<?php if ( '' !== (string) $submitted_at ) : ?>
							<span class="lrn-grading-signal">
								<i data-lucide="clock" aria-hidden="true"></i>
								<?php
								printf(
									/* translators: %s: date and time the attempt was submitted */
									esc_html__( 'Submitted %s', 'learnomy' ),
									esc_html( date_i18n( $date_format, strtotime( (string) $submitted_at ) ) )
								);
								?>
							</span>
						<?php endif; ?>
						<?php foreach ( $lrn_signals as $lrn_signal ) : ?>
							<span class="lrn-grading-signal<?php echo $lrn_signal['flag'] ? ' lrn-grading-signal--flagged' : ''; ?>">
								<i data-lucide="<?php echo esc_attr( (string) $lrn_signal['icon'] ); ?>" aria-hidden="true"></i>
								<?php echo esc_html( (string) $lrn_signal['text'] ); ?>
							</span>
						<?php endforeach; ?>
						<?php if ( ! empty( $tab_flagged ) ) : ?>
							<span class="lrn-grading-signal__hint"><?php esc_html_e( 'Advisory only, not proof of misconduct.', 'learnomy' ); ?></span>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<div class="lrn-grading" data-grading-attempt="<?php echo absint( $attempt_id ); ?>">

					<div class="lrn-notice lrn-notice--success" data-grading-done <?php echo (int) $pending_count > 0 ? 'hidden' : ''; ?>>
						<i data-lucide="check-circle" aria-hidden="true"></i>
						<span><?php esc_html_e( 'Every response on this attempt is graded. The student\'s result is final.', 'learnomy' ); ?></span>
					</div>

					<?php if ( empty( $rows ) ) : ?>
						<div class="lrn-empty-state">
							<i data-lucide="clipboard-check" class="lrn-empty-state__icon" aria-hidden="true"></i>
							<h3 class="lrn-empty-state__title"><?php esc_html_e( 'This attempt has no recorded responses', 'learnomy' ); ?></h3>
						</div>
					<?php endif; ?>

					<?php foreach ( $rows as $lrn_row ) : ?>
						<article class="lrn-card lrn-grading-card<?php echo $lrn_row['graded'] ? ' is-graded' : ''; ?>"
							data-response-id="<?php echo absint( $lrn_row['id'] ); ?>"
							data-max="<?php echo esc_attr( (string) $lrn_row['max'] ); ?>">

							<header class="lrn-grading-card__head">
								<h2 class="lrn-grading-card__title">
									<?php
									printf(
										/* translators: 1: question number, 2: question prompt */
										esc_html__( 'Q%1$d. %2$s', 'learnomy' ),
										(int) $lrn_row['index'],
										esc_html( (string) $lrn_row['prompt'] )
									);
									?>
								</h2>
								<p class="lrn-grading-card__meta">
									<span class="lrn-badge <?php echo $lrn_row['graded'] ? 'lrn-badge--green' : ''; ?>" data-grade-badge>
										<?php echo $lrn_row['graded'] ? esc_html__( 'Graded', 'learnomy' ) : esc_html__( 'Needs grading', 'learnomy' ); ?>
									</span>
									<span class="lrn-muted">
										<?php
										printf(
											/* translators: 1: question type, 2: maximum marks */
											esc_html__( '%1$s · max %2$s', 'learnomy' ),
											esc_html( (string) $lrn_row['type_label'] ),
											esc_html( (string) $lrn_row['max'] )
										);
										?>
									</span>
								</p>
							</header>

							<div class="lrn-grading-card__answer">
								<h3 class="lrn-grading-card__label"><?php printf( esc_html( /* translators: %s: singular label for a student (e.g. "Learner"). */ __( '%s answer', 'learnomy' ) ), esc_html( \Learnomy\student_label() ) ); ?></h3>
								<div class="lrn-grading-card__answer-body">
									<?php echo $lrn_row['answer_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by Grading_Service::render_answer(). ?>
								</div>
							</div>

							<div class="lrn-grading-card__result" data-grade-result <?php echo $lrn_row['graded'] ? '' : 'hidden'; ?>>
								<p>
									<strong><?php esc_html_e( 'Score', 'learnomy' ); ?>:</strong>
									<span data-grade-score-out><?php echo esc_html( (string) $lrn_row['score'] ); ?></span>
									/ <?php echo esc_html( (string) $lrn_row['max'] ); ?>
								</p>
								<p data-grade-feedback-line <?php echo '' === (string) $lrn_row['feedback'] ? 'hidden' : ''; ?>>
									<strong><?php esc_html_e( 'Feedback', 'learnomy' ); ?>:</strong>
									<span data-grade-feedback-out><?php echo nl2br( esc_html( (string) $lrn_row['feedback'] ) ); ?></span>
								</p>
							</div>

							<?php if ( ! $lrn_row['graded'] ) : ?>
								<div class="lrn-grading-card__form" data-grade-form>
									<div class="lrn-form-field lrn-form-field--score">
										<label for="lrn-score-<?php echo absint( $lrn_row['id'] ); ?>"><?php esc_html_e( 'Score', 'learnomy' ); ?></label>
										<div class="lrn-grading-card__score">
											<input type="number" id="lrn-score-<?php echo absint( $lrn_row['id'] ); ?>"
												class="lrn-input" data-grade-score inputmode="decimal"
												min="0" max="<?php echo esc_attr( (string) $lrn_row['max'] ); ?>" step="0.5"
												value="">
											<span class="lrn-muted">/ <?php echo esc_html( (string) $lrn_row['max'] ); ?></span>
										</div>
									</div>
									<div class="lrn-form-field">
										<label for="lrn-feedback-<?php echo absint( $lrn_row['id'] ); ?>"><?php esc_html_e( 'Feedback (optional)', 'learnomy' ); ?></label>
										<textarea id="lrn-feedback-<?php echo absint( $lrn_row['id'] ); ?>"
											class="lrn-input" rows="3" data-grade-feedback
											placeholder="<?php esc_attr_e( 'Shown to the student with their result.', 'learnomy' ); ?>"></textarea>
									</div>
									<div class="lrn-grading-card__actions">
										<button type="button" class="lrn-btn lrn-btn--primary" data-grade-save>
											<?php esc_html_e( 'Save grade', 'learnomy' ); ?>
										</button>
									</div>
									<?php
									// Same extension seam, same signature, as the wp-admin
									// grader — Pro's rubric grader attaches here so the two
									// screens offer the identical control.
									do_action( 'learnomy_quiz_grading_response_controls', $lrn_row['response'], $lrn_row['attempt'], $lrn_row['question'] );
									?>
								</div>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>

			<?php elseif ( 'denied' === $mode ) : ?>

				<?php
				$account_page = array(
					'icon'     => 'clipboard-check',
					'title'    => __( 'Grading', 'learnomy' ),
					'subtitle' => '',
				);
				require \Learnomy\template_path( 'partials/account-page-header.php' );
				?>
				<div class="lrn-empty-state">
					<i data-lucide="lock" class="lrn-empty-state__icon" aria-hidden="true"></i>
					<h3 class="lrn-empty-state__title"><?php esc_html_e( 'Nothing here for you to grade', 'learnomy' ); ?></h3>
					<p class="lrn-empty-state__desc"><?php esc_html_e( 'Grading covers the courses you own. That attempt is not one of them, or you do not own a course yet.', 'learnomy' ); ?></p>
				</div>

			<?php else : ?>

				<?php
				$account_page = array(
					'icon'     => 'clipboard-check',
					'title'    => __( 'Grading', 'learnomy' ),
					'subtitle' => __( 'Quiz attempts that need a human score. Grade them so the student gets their result.', 'learnomy' ),
				);
				require \Learnomy\template_path( 'partials/account-page-header.php' );
				?>

				<nav class="lrn-tabs" aria-label="<?php esc_attr_e( 'Grading views', 'learnomy' ); ?>">
					<a class="lrn-tab<?php echo $is_graded_view ? '' : ' is-active'; ?>"
						href="<?php echo esc_url( (string) $pending_url ); ?>"
						<?php echo $is_graded_view ? '' : 'aria-current="page"'; ?>>
						<?php esc_html_e( 'Awaiting grading', 'learnomy' ); ?>
					</a>
					<a class="lrn-tab<?php echo $is_graded_view ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( (string) $graded_url ); ?>"
						<?php echo $is_graded_view ? 'aria-current="page"' : ''; ?>>
						<?php esc_html_e( 'Graded', 'learnomy' ); ?>
					</a>
				</nav>

				<div class="lrn-grading-toolbar">
					<p class="lrn-grading-toolbar__count">
						<?php
						if ( $is_graded_view ) {
							printf(
								/* translators: %s: formatted count of graded attempts */
								esc_html( _n( '%s graded attempt', '%s graded attempts', $total, 'learnomy' ) ),
								esc_html( number_format_i18n( $total ) )
							);
						} else {
							printf(
								/* translators: %s: formatted count of attempts awaiting grading */
								esc_html( _n( '%s attempt awaiting grading', '%s attempts awaiting grading', $total, 'learnomy' ) ),
								esc_html( number_format_i18n( $total ) )
							);
						}
						?>
					</p>

					<?php if ( count( $filter_courses ) > 1 ) : ?>
						<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="lrn-grading-toolbar__filter">
							<?php if ( $is_graded_view ) : ?>
								<input type="hidden" name="status" value="graded">
							<?php endif; ?>
							<label class="screen-reader-text" for="lrn-grading-course"><?php esc_html_e( 'Filter by course', 'learnomy' ); ?></label>
							<select name="course" id="lrn-grading-course" class="lrn-input">
								<option value="0"><?php esc_html_e( 'All courses', 'learnomy' ); ?></option>
								<?php foreach ( $filter_courses as $lrn_course ) : ?>
									<option value="<?php echo absint( $lrn_course['id'] ); ?>" <?php selected( $course_id, (int) $lrn_course['id'] ); ?>>
										<?php echo esc_html( (string) $lrn_course['title'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="lrn-btn lrn-btn--secondary lrn-btn--sm"><?php esc_html_e( 'Filter', 'learnomy' ); ?></button>
						</form>
					<?php endif; ?>
				</div>

				<?php if ( empty( $rows ) ) : ?>
					<div class="lrn-empty-state">
						<i data-lucide="clipboard-check" class="lrn-empty-state__icon" aria-hidden="true"></i>
						<h3 class="lrn-empty-state__title">
							<?php echo $is_graded_view ? esc_html__( 'Nothing graded yet', 'learnomy' ) : esc_html__( 'Nothing to grade', 'learnomy' ); ?>
						</h3>
						<p class="lrn-empty-state__desc">
							<?php
							echo $is_graded_view
								? esc_html__( 'Attempts you have graded by hand appear here, so you can look back at a score or the feedback you left.', 'learnomy' )
								: esc_html__( 'When a student submits a quiz with essay or other hand-marked questions, it lands here for you to review.', 'learnomy' );
							?>
						</p>
					</div>
				<?php else : ?>
					<ul class="lrn-grading-queue" role="list">
						<?php foreach ( $rows as $lrn_row ) : ?>
							<?php
							$lrn_owed = (int) $lrn_row['ungraded_count'] > 0;
							$lrn_date = $is_graded_view ? (string) $lrn_row['graded_at'] : (string) $lrn_row['submitted_at'];
							?>
							<li class="lrn-grading-queue__item">
								<div class="lrn-grading-queue__identity">
									<?php echo $lrn_row['student_avatar']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() is safe core output. ?>
									<div class="lrn-grading-queue__text">
										<span class="lrn-grading-queue__student"><?php echo esc_html( (string) $lrn_row['student_name'] ); ?></span>
										<span class="lrn-grading-queue__quiz"><?php echo esc_html( (string) $lrn_row['quiz_title'] ); ?></span>
										<span class="lrn-grading-queue__meta">
											<?php
											if ( '' !== $lrn_date ) {
												echo esc_html(
													$is_graded_view
														/* translators: %s: date and time the attempt was graded */
														? sprintf( __( 'Graded %s', 'learnomy' ), date_i18n( $date_format, strtotime( $lrn_date ) ) )
														/* translators: %s: date and time the attempt was submitted */
														: sprintf( __( 'Submitted %s', 'learnomy' ), date_i18n( $date_format, strtotime( $lrn_date ) ) )
												);
											}
											?>
										</span>
									</div>
								</div>
								<div class="lrn-grading-queue__status">
									<span class="lrn-badge <?php echo $lrn_owed ? '' : 'lrn-badge--green'; ?>">
										<?php
										if ( $lrn_owed ) {
											printf(
												/* translators: %s: number of responses awaiting grading */
												esc_html( _n( '%s to grade', '%s to grade', (int) $lrn_row['ungraded_count'], 'learnomy' ) ),
												esc_html( number_format_i18n( (int) $lrn_row['ungraded_count'] ) )
											);
										} else {
											esc_html_e( 'Graded', 'learnomy' );
										}
										?>
									</span>
									<a class="lrn-btn lrn-btn--primary lrn-btn--sm" href="<?php echo esc_url( (string) $lrn_row['grade_url'] ); ?>">
										<?php echo $lrn_owed ? esc_html__( 'Grade', 'learnomy' ) : esc_html__( 'Review', 'learnomy' ); ?>
									</a>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php
					$total_pages     = (int) ceil( $total / $per_page );
					$pagination_url  = static function ( int $n ) use ( $status, $course_id ): string {
						// Built from the registry so /instructor/grading/page/N/ matches
						// the emitted rewrite rule, with the tab + course filter carried
						// along instead of being reset by paging.
						$url  = \Learnomy\route_url( 'instructor-grading', $n > 1 ? array( 'paged' => $n ) : array() );
						$args = array();
						if ( 'pending' !== $status ) {
							$args['status'] = $status;
						}
						if ( $course_id > 0 ) {
							$args['course'] = $course_id;
						}
						return esc_url_raw( $args ? add_query_arg( $args, $url ) : $url );
					};
					$pagination_aria = __( 'Grading queue pages', 'learnomy' );
					require \Learnomy\template_path( 'partials/pagination.php' );
	?>
				<?php endif; ?>

			<?php endif; ?>

		</div><!-- .lrn-account-layout__content -->
	</div><!-- .lrn-account-layout -->

</main>
