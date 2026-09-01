<?php
/**
 * Account quiz results — /account/quiz-results/
 *
 * The learner's quiz attempt history (list) and per-attempt review (detail,
 * via ?attempt=N). Same account shell as every other account section; the
 * review reuses the shared partials/quiz-attempt-review.php so it matches the
 * live quiz results screen exactly.
 *
 * View-data (Account_Quiz_Results_View_Data):
 *   $attempts, $total, $page, $per_page, $base_url, $review, $review_denied.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$attempts      = isset( $attempts ) && is_array( $attempts ) ? $attempts : array();
$total         = isset( $total ) ? (int) $total : 0;
$page          = isset( $page ) ? max( 1, (int) $page ) : 1;
$per_page      = isset( $per_page ) ? max( 1, (int) $per_page ) : 20;
$base_url      = isset( $base_url ) ? (string) $base_url : '';
$review        = isset( $review ) && is_array( $review ) ? $review : null;
$review_denied = ! empty( $review_denied );
?>
<main class="lrn-container lrn-account-quiz-results" id="lrn-account-quiz-results">

	<?php
	$breadcrumb_items = \Learnomy\account_breadcrumb( __( 'Quiz Attempts', 'learnomy' ) );
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<div class="lrn-account-layout">
		<?php
		$account_nav_active = 'account-quiz-results';
		require \Learnomy\template_path( 'partials/account-nav.php' );
		?>
		<div class="lrn-account-layout__content">

			<?php
			$account_page = array(
				'icon'     => 'clipboard-check',
				'title'    => __( 'Quiz Attempts', 'learnomy' ),
				'subtitle' => __( 'Your quiz attempts. Revisit your score and see what you answered.', 'learnomy' ),
			);
			require \Learnomy\template_path( 'partials/account-page-header.php' );

			if ( $review ) :
				// ── Detail: one attempt's review ──────────────────────────
				$lrn_attempt = (array) ( $review['attempt'] ?? array() );
				$lrn_passed  = ! empty( $lrn_attempt['passed'] );
				$lrn_pct     = (float) ( $lrn_attempt['percentage'] ?? 0 );
				?>
				<p class="lrn-account-back">
					<a href="<?php echo esc_url( (string) ( $review['back_url'] ?? $base_url ) ); ?>">
						&larr; <?php esc_html_e( 'All quiz results', 'learnomy' ); ?>
					</a>
				</p>

				<div class="lrn-quiz__results-summary lrn-card">
					<h2 class="lrn-quiz__results-title"><?php echo esc_html( (string) ( $review['quiz_title'] ?? '' ) ); ?></h2>
					<?php if ( '' !== (string) ( $review['course_title'] ?? '' ) ) : ?>
						<p class="lrn-quiz__results-breakdown"><?php echo esc_html( (string) $review['course_title'] ); ?></p>
					<?php endif; ?>
					<p class="lrn-quiz__results-score">
						<?php
						/* translators: %s: score percentage */
						printf( esc_html__( 'You scored %s%%', 'learnomy' ), esc_html( (string) round( $lrn_pct, 2 ) ) );
						?>
					</p>
					<p class="lrn-quiz__results-status">
						<span class="lrn-badge <?php echo $lrn_passed ? 'lrn-badge--green' : 'lrn-badge--red'; ?>">
							<?php echo $lrn_passed ? esc_html__( 'Passed', 'learnomy' ) : esc_html__( 'Not passed', 'learnomy' ); ?>
						</span>
					</p>
					<p class="lrn-quiz__results-export">
						<a class="lrn-btn lrn-btn--secondary lrn-btn--sm"
							href="
							<?php
							echo esc_url(
								add_query_arg(
									array(
										'attempt' => (int) ( $lrn_attempt['id'] ?? 0 ),
										'export'  => 'pdf',
									),
									$base_url
								)
							);
							?>
									">
							<i data-lucide="download" aria-hidden="true"></i>
							<?php esc_html_e( 'Download PDF', 'learnomy' ); ?>
						</a>
					</p>
				</div>

				<?php
				if ( ! empty( $review['no_feedback'] ) ) {
					echo '<p class="lrn-quiz__attempts-exhausted">' . esc_html__( 'This quiz does not show answer feedback.', 'learnomy' ) . '</p>';
				} else {
					$review_rows   = (array) ( $review['rows'] ?? array() );
					$review_groups = (array) ( $review['groups'] ?? array() );
					require \Learnomy\template_path( 'partials/quiz-attempt-review.php' );
				}
			elseif ( $review_denied ) :
				?>
				<div class="lrn-empty-state">
					<i data-lucide="lock" class="lrn-empty-state__icon" aria-hidden="true"></i>
					<h3 class="lrn-empty-state__title"><?php esc_html_e( 'Result not available', 'learnomy' ); ?></h3>
					<p class="lrn-empty-state__desc"><?php esc_html_e( 'That quiz result could not be found, or it is not yours to view.', 'learnomy' ); ?></p>
				</div>
				<?php
			elseif ( empty( $attempts ) ) :
				?>
				<div class="lrn-empty-state">
					<i data-lucide="clipboard-check" class="lrn-empty-state__icon" aria-hidden="true"></i>
					<h3 class="lrn-empty-state__title"><?php esc_html_e( 'No quiz attempts yet', 'learnomy' ); ?></h3>
					<p class="lrn-empty-state__desc"><?php esc_html_e( 'When you take a quiz in your courses, your scores and answers will show up here.', 'learnomy' ); ?></p>
				</div>
				<?php
			else :
				// ── List: attempt history ─────────────────────────────────
				?>
				<div class="lrn-table-wrap">
					<table class="lrn-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Quiz', 'learnomy' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Course', 'learnomy' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Score', 'learnomy' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Result', 'learnomy' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Date', 'learnomy' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'learnomy' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $attempts as $lrn_a ) : ?>
								<?php $lrn_in_progress = ! empty( $lrn_a['is_in_progress'] ); ?>
								<tr>
									<td><?php echo esc_html( (string) $lrn_a['quiz_title'] ); ?></td>
									<td><?php echo esc_html( (string) $lrn_a['course_title'] ); ?></td>
									<td>
										<?php
										// An in-progress attempt has no score yet — show a dash,
										// not a misleading 0%.
										echo $lrn_in_progress ? '&mdash;' : esc_html( (string) round( (float) $lrn_a['percentage'], 2 ) . '%' );
										?>
									</td>
									<td>
										<?php if ( $lrn_in_progress ) : ?>
											<span class="lrn-badge"><?php esc_html_e( 'In progress', 'learnomy' ); ?></span>
										<?php elseif ( 'submitted' === $lrn_a['status'] ) : ?>
											<span class="lrn-badge"><?php esc_html_e( 'Awaiting grading', 'learnomy' ); ?></span>
										<?php else : ?>
											<span class="lrn-badge <?php echo $lrn_a['passed'] ? 'lrn-badge--green' : 'lrn-badge--red'; ?>">
												<?php echo $lrn_a['passed'] ? esc_html__( 'Passed', 'learnomy' ) : esc_html__( 'Not passed', 'learnomy' ); ?>
											</span>
										<?php endif; ?>
									</td>
									<td>
										<?php
										$lrn_ts = $lrn_a['date'] ? strtotime( (string) $lrn_a['date'] ) : 0;
										echo esc_html( $lrn_ts ? date_i18n( (string) get_option( 'date_format' ), $lrn_ts ) : '—' );
										?>
									</td>
									<td>
										<?php if ( $lrn_in_progress && ! empty( $lrn_a['resume_url'] ) ) : ?>
											<a class="lrn-btn lrn-btn--primary lrn-btn--sm" href="<?php echo esc_url( (string) $lrn_a['resume_url'] ); ?>">
												<?php esc_html_e( 'Resume', 'learnomy' ); ?>
											</a>
										<?php elseif ( ! empty( $lrn_a['reviewable'] ) ) : ?>
											<a class="lrn-btn lrn-btn--secondary lrn-btn--sm" href="<?php echo esc_url( (string) $lrn_a['review_url'] ); ?>">
												<?php esc_html_e( 'Review', 'learnomy' ); ?>
											</a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php
				$total_pages     = (int) ceil( $total / $per_page );
				$pagination_url  = static function ( int $n ): string {
					// Build /account/quiz-results/page/N/ via the registry so the link
					// matches the emitted rewrite rule (no ?paged= 404).
					return esc_url_raw( \Learnomy\route_url( 'account-quiz-results', $n > 1 ? array( 'paged' => $n ) : array() ) );
				};
				$pagination_aria = __( 'Quiz results pages', 'learnomy' );
				require \Learnomy\template_path( 'partials/pagination.php' );
				?>
			<?php endif; ?>

		</div><!-- .lrn-account-layout__content -->
	</div><!-- .lrn-account-layout -->

</main>
