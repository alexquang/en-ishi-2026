<?php
/**
 * Admin per-instructor detail view.
 *
 * @package Learnomy
 * @var \WP_User      $user            The instructor.
 * @var object|null   $profile         lrn_instructors row.
 * @var object[]      $courses         Courses they teach (author OR co).
 * @var float         $earned          Lifetime instructor earnings.
 * @var float         $pending         Pending payout.
 * @var object[]      $withdrawals     Recent withdrawal requests.
 * @var float         $platform_rate   Effective platform commission rate.
 * @var bool          $has_custom_rate Whether a per-instructor rule applies.
 * @var string        $currency        Store currency code.
 * @var bool          $is_pro          Whether Pro is active.
 * @var int           $instructor_id   The instructor user ID.
 */

defined( 'ABSPATH' ) || exit;

$list_url       = admin_url( 'admin.php?page=learnomy-instructors' );
$profile_url    = \Learnomy\route_url( 'instructor-profile', array( 'slug' => $user->user_nicename ) );
$instructor_pct = round( 100 - (float) $platform_rate, 2 );
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="user" class="lrn-page-header__icon"></i>
				<?php echo esc_html( $user->display_name ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a class="lrn-admin-btn" href="<?php echo esc_url( $list_url ); ?>">
				<i data-lucide="arrow-left"></i>
				<?php esc_html_e( 'All instructors', 'learnomy' ); ?>
			</a>
			<a class="lrn-admin-btn" href="<?php echo esc_url( $profile_url ); ?>" target="_blank" rel="noopener">
				<i data-lucide="external-link"></i>
				<?php esc_html_e( 'View public profile', 'learnomy' ); ?>
			</a>
			<a class="lrn-admin-btn" href="mailto:<?php echo esc_attr( $user->user_email ); ?>">
				<i data-lucide="mail"></i>
				<?php esc_html_e( 'Email instructor', 'learnomy' ); ?>
			</a>

			<?php $lrn_is_verified = 'verified' === ( $profile->verification_status ?? 'unverified' ); ?>
			<button type="button" class="lrn-admin-btn"
					data-action="learnomy_admin_verify_instructor"
					data-instructor-id="<?php echo absint( $instructor_id ); ?>"
					data-verified="<?php echo $lrn_is_verified ? 0 : 1; ?>"
					data-confirm="<?php echo esc_attr( $lrn_is_verified ? __( 'Remove this instructor\'s verified badge?', 'learnomy' ) : __( 'Mark this instructor as verified?', 'learnomy' ) ); ?>">
				<i data-lucide="<?php echo $lrn_is_verified ? 'shield-off' : 'shield-check'; ?>"></i>
				<?php echo $lrn_is_verified ? esc_html__( 'Unverify', 'learnomy' ) : esc_html__( 'Verify', 'learnomy' ); ?>
			</button>

			<?php
			// Per-instructor course-approval override. Read the meta here (rather
			// than through a passed var) so this toggle needs no change to the
			// page controller. When on, this instructor's course publishes are
			// downgraded to Pending Review even if the site allows self-publish
			// (Course_Service::requires_approval()).
			$lrn_require_approval = (bool) get_user_meta( $instructor_id, 'learnomy_require_course_approval', true );
			?>
			<button type="button" class="lrn-admin-btn"
					data-action="learnomy_admin_set_require_approval"
					data-instructor-id="<?php echo absint( $instructor_id ); ?>"
					data-require="<?php echo $lrn_require_approval ? 0 : 1; ?>"
					data-confirm="<?php echo esc_attr( $lrn_require_approval ? __( 'Let this instructor publish courses without approval?', 'learnomy' ) : __( 'Require admin approval before this instructor can publish courses?', 'learnomy' ) ); ?>">
				<i data-lucide="<?php echo $lrn_require_approval ? 'unlock' : 'shield-alert'; ?>"></i>
				<?php echo $lrn_require_approval ? esc_html__( 'Allow self-publish', 'learnomy' ) : esc_html__( 'Require course approval', 'learnomy' ); ?>
			</button>

			<?php
			/*
			 * Both buttons carried `data-application-id`, which actions.js does
			 * not read - it takes `data-id` plus `data-id-field`. So the id was
			 * never collected, the path built `.../NaN/approve` (or /reject),
			 * and BOTH controls on this screen were dead. Found alongside
			 * Basecamp 10190089031; the card only named the missing rejection
			 * reason on the LIST screen, so nobody had reported that the
			 * per-instructor screen's buttons do nothing at all.
			 *
			 * Reject prompts for a reason, matching the list and
			 * application-detail.php: the applicant reads whatever is typed
			 * here, and a plain confirm leaves them with the generic message.
			 */
			?>
			<?php if ( $application && 'pending' === ( $application->status ?? '' ) ) : ?>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--primary"
						data-action="learnomy_approve_application"
						data-id="<?php echo absint( $application->id ); ?>"
						data-id-field="application_id"
						data-confirm="<?php esc_attr_e( 'Approve this instructor application? The instructor role will be granted.', 'learnomy' ); ?>">
					<i data-lucide="check"></i>
					<?php esc_html_e( 'Approve', 'learnomy' ); ?>
				</button>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--danger"
						data-action="learnomy_reject_application"
						data-id="<?php echo absint( $application->id ); ?>"
						data-id-field="application_id"
						data-prompt="<?php esc_attr_e( 'Tell the applicant why this application was declined. They will see this reason.', 'learnomy' ); ?>"
						data-prompt-label="<?php esc_attr_e( 'Rejection reason', 'learnomy' ); ?>">
					<i data-lucide="x"></i>
					<?php esc_html_e( 'Reject', 'learnomy' ); ?>
				</button>
			<?php endif; ?>

			<?php
			/**
			 * Pro extension point for per-instructor actions, rendered in the
			 * detail header action cluster.
			 *
			 * @param int $instructor_id The instructor's user ID.
			 */
			do_action( 'learnomy_admin_instructor_detail_actions', (int) $instructor_id );
			?>
		</div>
	</div>

	<hr class="wp-header-end">

	<div class="lrn-admin-grid lrn-admin-grid--2">

		<!-- Profile -->
		<div class="lrn-admin-card">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Profile', 'learnomy' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Email', 'learnomy' ); ?></th>
					<td><?php echo esc_html( $user->user_email ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Joined', 'learnomy' ); ?></th>
					<td><?php echo esc_html( \Learnomy\date_label( $user->user_registered, '—', get_option( 'date_format' ) ) ); ?></td>
				</tr>
				<?php if ( $profile && ! empty( $profile->headline ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Headline', 'learnomy' ); ?></th>
						<td><?php echo esc_html( (string) $profile->headline ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( $profile && ! empty( $profile->expertise ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Expertise', 'learnomy' ); ?></th>
						<td><?php echo esc_html( (string) $profile->expertise ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( $profile && ! empty( $profile->bio ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Bio', 'learnomy' ); ?></th>
						<td><?php echo esc_html( (string) $profile->bio ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( $profile && ! empty( $profile->location ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Location', 'learnomy' ); ?></th>
						<td><?php echo esc_html( (string) $profile->location ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( $profile && ! empty( $profile->website_url ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Website', 'learnomy' ); ?></th>
						<td><a href="<?php echo esc_url( (string) $profile->website_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) $profile->website_url ); ?></a></td>
					</tr>
				<?php endif; ?>
				<?php if ( $profile && ! empty( $profile->twitter_handle ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Twitter', 'learnomy' ); ?></th>
						<td><?php echo esc_html( '@' . ltrim( (string) $profile->twitter_handle, '@' ) ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( $profile && ! empty( $profile->linkedin_url ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'LinkedIn', 'learnomy' ); ?></th>
						<td><a href="<?php echo esc_url( (string) $profile->linkedin_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) $profile->linkedin_url ); ?></a></td>
					</tr>
				<?php endif; ?>
				<?php
				// Custom account fields captured for this instructor. Mirrors the
				// same block on student-detail.php: the owner could read a
				// student's answers and not an instructor's, though both filled
				// the same fields at registration.
				foreach ( ( new \Learnomy\Services\Custom_Fields() )->definitions() as $lrn_cf ) :
					$lrn_cf_val = (string) get_user_meta( (int) $user->ID, \Learnomy\Services\Custom_Fields::META_PREFIX . $lrn_cf['key'], true );
					if ( 'checkbox' === $lrn_cf['type'] ) {
						$lrn_cf_val = '' !== $lrn_cf_val ? __( 'Yes', 'learnomy' ) : __( 'No', 'learnomy' );
					}
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $lrn_cf['label'] ); ?></th>
						<td>
							<?php
							echo '' !== $lrn_cf_val
								? esc_html( $lrn_cf_val )
								: '<span class="lrn-muted">-</span>';
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Verification', 'learnomy' ); ?></th>
					<td><?php echo \Learnomy\Admin\UX::status_badge( (string) ( $profile->verification_status ?? 'unverified' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				</tr>
			</table>
		</div>

		<!-- Earnings + rate -->
		<div class="lrn-admin-card">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Earnings', 'learnomy' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Lifetime earned', 'learnomy' ); ?></th>
					<td><?php echo esc_html( $currency . ' ' . number_format_i18n( (float) $earned, 2 ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Pending payout', 'learnomy' ); ?></th>
					<td><?php echo esc_html( $currency . ' ' . number_format_i18n( (float) $pending, 2 ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Commission rate', 'learnomy' ); ?></th>
					<td>
						<?php
						printf(
							/* translators: 1: instructor share %, 2: platform share % */
							esc_html__( 'Instructor %1$s%% / platform %2$s%%', 'learnomy' ),
							esc_html( number_format_i18n( $instructor_pct, 2 ) ),
							esc_html( number_format_i18n( (float) $platform_rate, 2 ) )
						);
						echo $has_custom_rate
							? ' <span class="lrn-status-badge lrn-status-badge--success">' . esc_html__( 'Custom', 'learnomy' ) . '</span>'
							: ' <span class="lrn-muted">' . esc_html__( '(default)', 'learnomy' ) . '</span>';
						?>
						<p class="description">
							<?php if ( $is_pro ) : ?>
								<?php esc_html_e( 'Set a per-instructor rate from Commission Rules.', 'learnomy' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Per-instructor custom rates are a Pro feature. Free uses the global and per-course rates.', 'learnomy' ); ?>
							<?php endif; ?>
						</p>
						<?php if ( $is_pro ) : ?>
							<a class="lrn-admin-btn lrn-admin-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-earnings' ) ); ?>">
								<i data-lucide="percent"></i>
								<?php esc_html_e( 'Adjust commission rate', 'learnomy' ); ?>
							</a>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>
	</div>

	<!-- Signup application — what the instructor submitted when applying. -->
	<?php if ( $application ) : ?>
		<div class="lrn-admin-card">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Signup application', 'learnomy' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Application status', 'learnomy' ); ?></th>
					<td>
						<?php echo \Learnomy\Admin\UX::status_badge( (string) ( $application->status ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( ! empty( $application->reviewed_at ) ) : ?>
							<span class="lrn-muted">
								<?php
								printf(
									/* translators: %s: review date */
									esc_html__( 'reviewed %s', 'learnomy' ),
									esc_html( \Learnomy\date_label( $application->reviewed_at, '—', get_option( 'date_format' ) ) )
								);
								?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Submitted', 'learnomy' ); ?></th>
					<td><?php echo esc_html( \Learnomy\date_label( $application->created_at, '—', get_option( 'date_format' ) ) ); ?></td>
				</tr>
				<?php if ( ! empty( $application->expertise ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Expertise', 'learnomy' ); ?></th>
						<td><?php echo esc_html( (string) $application->expertise ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( ! empty( $application->teaching_experience ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Teaching experience', 'learnomy' ); ?></th>
						<td><?php echo esc_html( (string) $application->teaching_experience ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( ! empty( $application->bio ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Bio (as submitted)', 'learnomy' ); ?></th>
						<td><?php echo nl2br( esc_html( (string) $application->bio ) ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( ! empty( $application->course_idea ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Course idea', 'learnomy' ); ?></th>
						<td><?php echo nl2br( esc_html( (string) $application->course_idea ) ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( ! empty( $application->payout_method ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Payout method', 'learnomy' ); ?></th>
						<td><?php echo esc_html( (string) $application->payout_method ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( 'rejected' === ( $application->status ?? '' ) && ! empty( $application->rejection_reason ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rejection reason', 'learnomy' ); ?></th>
						<td><?php echo esc_html( (string) $application->rejection_reason ); ?></td>
					</tr>
				<?php endif; ?>
			</table>
		</div>
	<?php endif; ?>

	<!-- Courses -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title">
			<?php
			printf(
				/* translators: %s: course count */
				esc_html__( 'Courses (%s)', 'learnomy' ),
				esc_html( number_format_i18n( count( $courses ) ) )
			);
			?>
		</h2>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Course', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Role', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Students', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $courses ) ) : ?>
					<tr><td colspan="4" class="lrn-muted"><?php esc_html_e( 'Not teaching any courses yet.', 'learnomy' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $courses as $lrn_course ) : ?>
						<?php $lrn_is_author = (int) ( $lrn_course->author_id ?? 0 ) === $instructor_id; ?>
						<tr>
							<td><?php echo esc_html( (string) $lrn_course->title ); ?></td>
							<td>
								<?php if ( $lrn_is_author ) : ?>
									<span class="lrn-status-badge lrn-status-badge--success"><?php esc_html_e( 'Main author', 'learnomy' ); ?></span>
								<?php else : ?>
									<span class="lrn-status-badge"><?php esc_html_e( 'Co-instructor', 'learnomy' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) ( $lrn_course->status ?? '' ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $lrn_course->student_count ?? 0 ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
	</div>

	<!-- Withdrawals -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Recent withdrawals', 'learnomy' ); ?></h2>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Requested', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Method', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $withdrawals ) ) : ?>
					<tr><td colspan="5" class="lrn-muted"><?php esc_html_e( 'No withdrawal requests.', 'learnomy' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $withdrawals as $lrn_wd ) : ?>
						<?php
						$lrn_wd_detail_url = add_query_arg(
							array(
								'page'          => 'learnomy-withdrawal-detail',
								'withdrawal_id' => absint( $lrn_wd->id ),
							),
							admin_url( 'admin.php' )
						);
						?>
						<tr>
							<td><?php echo esc_html( \Learnomy\date_label( $lrn_wd->requested_at, '—', get_option( 'date_format' ) ) ); ?></td>
							<td><?php echo esc_html( strtoupper( (string) ( $lrn_wd->currency ?? $currency ) ) . ' ' . number_format_i18n( (float) ( $lrn_wd->amount ?? 0 ), 2 ) ); ?></td>
							<td><?php echo esc_html( (string) ( $lrn_wd->method ?? '—' ) ); ?></td>
							<td><?php echo \Learnomy\Admin\UX::status_badge( (string) ( $lrn_wd->status ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td>
								<?php
								$lrn_wd_actions = array(
									array(
										'label'   => __( 'View', 'learnomy' ),
										'href'    => $lrn_wd_detail_url,
										'icon'    => 'eye',
										'primary' => true,
									),
								);
								echo \Learnomy\Admin\UX::row_actions( $lrn_wd_actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
	</div>

</div><!-- /.learnomy-admin -->
