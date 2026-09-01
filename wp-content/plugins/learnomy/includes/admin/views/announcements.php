<?php
/**
 * Admin announcements view — create + list broadcast notifications.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Notification;

// Load all published courses for the dropdown.
$courses = Course::list_published( 1, 200 );

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$per_page = 20;
// phpcs:enable

// Load past announcements via model. Pagination supported as of 2026-05-01.
$past_announcements = Notification::get_past_announcements( $per_page, ( $paged - 1 ) * $per_page );
$total              = Notification::count_past_announcements();
$total_pages        = (int) ceil( $total / $per_page );
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="megaphone" class="lrn-page-header__icon"></i>
				<?php esc_html_e( 'Announcements', 'learnomy' ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions"></div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<!-- Create Announcement Form -->
	<div class="lrn-admin-card lrn-mb-6">
		<h2 class="lrn-admin-card__title">
			<i data-lucide="send" class="lrn-icon--sm" aria-hidden="true"></i>
			<?php esc_html_e( 'Create Announcement', 'learnomy' ); ?>
		</h2>
		<?php
		// Deliberately NO data-ajax-action here. announcements.js submits this form
		// over REST POST /admin/announcements; the admin-ajax action that attribute
		// used to name was deleted in Stage 5K-G. Re-adding it lets the generic
		// form handler in actions.js claim the form, and because
		// learnomy_send_announcement is absent from that file's REST_MAP the
		// handler falls through to admin-ajax, which answers 0 for an unregistered
		// action. That is exactly the "Action failed." toast that used to follow
		// every SUCCESSFUL broadcast.
		?>
		<form id="lrn-announcement-form">
			<?php wp_nonce_field( 'learnomy_admin', 'nonce' ); ?>

			<div class="lrn-form-grid">
				<div class="lrn-form-field">
					<label for="lrn-ann-title"><?php esc_html_e( 'Title', 'learnomy' ); ?></label>
					<input type="text" id="lrn-ann-title" name="title" class="regular-text" required
						placeholder="<?php esc_attr_e( 'e.g. New lesson added to Marketing 101', 'learnomy' ); ?>">
				</div>
				<div class="lrn-form-field">
					<label for="lrn-ann-message"><?php esc_html_e( 'Message', 'learnomy' ); ?></label>
					<textarea id="lrn-ann-message" name="message" class="large-text" rows="4" required
						placeholder="<?php esc_attr_e( 'Write the announcement body here…', 'learnomy' ); ?>"></textarea>
				</div>
				<div class="lrn-form-field">
					<label for="lrn-ann-course"><?php esc_html_e( 'Course', 'learnomy' ); ?></label>
					<select id="lrn-ann-course" name="course_id">
						<option value="0"><?php esc_html_e( 'All students', 'learnomy' ); ?></option>
						<?php foreach ( $courses as $course ) : ?>
							<option value="<?php echo (int) $course->id; ?>">
								<?php echo esc_html( $course->title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Select a course to notify its enrolled students, or "All students" for everyone.', 'learnomy' ); ?>
					</p>
				</div>
				<div class="lrn-form-field">
					<label><?php esc_html_e( 'Email', 'learnomy' ); ?></label>
					<label>
						<input type="checkbox" id="lrn-ann-email" name="send_email" value="1">
						<?php esc_html_e( 'Also send email to each recipient', 'learnomy' ); ?>
					</label>
				</div>
			</div>

			<p class="submit">
				<button type="submit" id="lrn-ann-submit" class="lrn-admin-btn lrn-admin-btn--primary">
					<?php esc_html_e( 'Send Announcement', 'learnomy' ); ?>
				</button>
				<span id="lrn-ann-spinner" class="spinner lrn-spinner-inline"></span>
			</p>
		</form>
	</div>

	<!-- Past Announcements -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title">
			<i data-lucide="history" class="lrn-icon--sm" aria-hidden="true"></i>
			<?php esc_html_e( 'Sent Announcements', 'learnomy' ); ?>
		</h2>
		<div class="lrn-admin-table-wrap">
			<table class="lrn-admin-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Title', 'learnomy' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Course', 'learnomy' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Message', 'learnomy' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sent Date', 'learnomy' ); ?></th>
						<th scope="col" class="lrn-col-actions"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $past_announcements ) ) : ?>
						<tr>
							<td colspan="5" class="lrn-no-items">
								<?php
								$lrn_empty_state = array(
									'icon'        => 'megaphone',
									'title'       => __( 'No announcements sent yet', 'learnomy' ),
									'description' => __( 'Use the form above to send your first announcement to all students or to a specific course\'s cohort.', 'learnomy' ),
								);
								include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
								?>
							</td>
						</tr>
					<?php else : ?>
						<?php
						foreach ( $past_announcements as $ann ) :
							$ann_course = ( (int) $ann->object_id > 0 ) ? Course::find( (int) $ann->object_id ) : null;
							?>
							<tr>
								<td><?php echo esc_html( $ann->title ); ?></td>
								<td><?php echo $ann_course ? esc_html( $ann_course->title ) : esc_html__( 'All students', 'learnomy' ); ?></td>
								<td><?php echo esc_html( wp_trim_words( $ann->message, 12 ) ); ?></td>
								<td><?php echo esc_html( \Learnomy\date_label( $ann->created_at, '—', get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
								<td class="lrn-col-actions">
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- values are escaped by the helper; the statement spans lines, so a single-line ignore would not cover it.
									echo \Learnomy\Admin\UX::row_actions(
										array(
											array(
												'label'   => __( 'Delete', 'learnomy' ),
												'icon'    => 'trash-2',
												'primary' => true,
												'danger'  => true,
												'attrs'   => array(
													'data-action'     => 'learnomy_delete_announcement',
													'data-created-at' => (string) $ann->created_at,
													'data-title'      => (string) $ann->title,
													'data-object-id'  => (string) absint( $ann->object_id ),
													'data-confirm'    => __( 'Delete this announcement? It is removed from every recipient\'s notifications. This cannot be undone.', 'learnomy' ),
												),
											),
										)
									);
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<?php
		$lrn_pagination = array(
			'current'  => $paged,
			'total'    => $total_pages,
			'results'  => $total,
			'per_page' => $per_page,
			'base_url' => admin_url( 'admin.php?page=learnomy-announcements' ),
		);
		require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
		?>
	</div>
</div>
