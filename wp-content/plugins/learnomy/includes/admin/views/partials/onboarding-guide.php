<?php
/**
 * Getting Started onboarding guide — dashboard partial.
 *
 * @package Learnomy
 *
 * @var array    $resolved    Map of step_id => 'pending' | 'completed' | 'skipped'.
 * @var \WP_User $user        Current user.
 * @var bool     $dismissed   Whether the panel has been dismissed.
 * @var bool     $demo_seeded Whether demo data is currently loaded.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Admin\Onboarding_State;

if ( ! empty( $dismissed ) ) {
	?>
	<div class="lrn-onboarding-reopen lrn-mb-4 lrn-text-right">
		<button type="button" class="lrn-admin-btn lrn-admin-btn--sm lrn-onb-reopen">
			<i data-lucide="book-open"></i>
			<?php esc_html_e( 'Reopen Getting Started Guide', 'learnomy' ); ?>
		</button>
	</div>
	<?php
	return;
}

// Ensure step 2 ("Explore your dashboard") auto-completes once the admin actually lands here.
Onboarding_State::mark_dashboard_seen( (int) $user->ID );

// Re-resolve after the side-effect above so the "seen_dashboard" check reflects reality.
$resolved    = Onboarding_State::resolve();
$steps       = Onboarding_State::get_steps();
$total       = Onboarding_State::count_total();
$done        = Onboarding_State::count_complete( $resolved );
$percent     = Onboarding_State::progress_percent( $resolved );
$is_all_done = Onboarding_State::is_all_done( $resolved );

$first_name = trim( (string) $user->first_name );
if ( '' === $first_name ) {
	$first_name = $user->display_name ? (string) $user->display_name : (string) $user->user_login;
}

// Split into completed vs active (pending + skipped).
$completed_steps = array();
$active_steps    = array();
foreach ( $steps as $step_id => $step ) {
	$step_status = $resolved[ $step_id ] ?? 'pending';
	if ( 'completed' === $step_status ) {
		$completed_steps[ $step_id ] = $step;
	} else {
		$active_steps[ $step_id ] = $step;
	}
}

/**
 * Render a single onboarding card.
 *
 * @param string $id     Step identifier.
 * @param array  $step   Step definition.
 * @param string $status 'pending' | 'completed' | 'skipped'.
 */
$render_card = static function ( string $card_id, array $step, string $status ): void {
	$classes = array( 'lrn-onboarding__card', 'lrn-onboarding__card--' . $status );
	?>
	<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-step="<?php echo esc_attr( $card_id ); ?>">
		<span class="lrn-onboarding__card-icon" aria-hidden="true">
			<?php if ( 'completed' === $status ) : ?>
				<i data-lucide="check-circle-2"></i>
			<?php else : ?>
				<i data-lucide="<?php echo esc_attr( $step['icon'] ); ?>"></i>
			<?php endif; ?>
		</span>
		<div class="lrn-onboarding__card-body">
			<h3 class="lrn-onboarding__card-title"><?php echo esc_html( $step['title'] ); ?></h3>
			<p class="lrn-onboarding__card-desc"><?php echo esc_html( $step['description'] ); ?></p>
			<?php if ( 'skipped' === $status ) : ?>
				<span class="lrn-onboarding__pill"><?php esc_html_e( 'Skipped', 'learnomy' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="lrn-onboarding__card-actions">
			<?php if ( 'skipped' === $status ) : ?>
				<button type="button" class="lrn-onb-undo-skip lrn-onboarding__ghost-btn" data-step="<?php echo esc_attr( $card_id ); ?>">
					<?php esc_html_e( 'Undo skip', 'learnomy' ); ?>
				</button>
			<?php elseif ( 'pending' === $status && ! empty( $step['skippable'] ) ) : ?>
				<button type="button" class="lrn-onb-skip lrn-onboarding__ghost-btn" data-step="<?php echo esc_attr( $card_id ); ?>">
					<?php esc_html_e( 'Skip', 'learnomy' ); ?>
				</button>
			<?php endif; ?>

			<?php if ( 'pending' === $status && ! empty( $step['cta'] ) && ! empty( $step['url'] ) ) : ?>
				<a href="<?php echo esc_url( $step['url'] ); ?>" class="lrn-admin-btn lrn-admin-btn--primary lrn-admin-btn--sm lrn-onboarding__cta">
					<?php echo esc_html( $step['cta'] ); ?>
					<i data-lucide="arrow-right" aria-hidden="true"></i>
				</a>
			<?php elseif ( 'pending' === $status && ! empty( $step['cta'] ) ) : ?>
				<button
					type="button"
					class="lrn-admin-btn lrn-admin-btn--primary lrn-admin-btn--sm lrn-onboarding__cta"
					data-onboarding-action="<?php echo esc_attr( $card_id ); ?>"
				>
					<?php echo esc_html( $step['cta'] ); ?>
					<i data-lucide="arrow-right" aria-hidden="true"></i>
				</button>
			<?php endif; ?>
		</div>
	</div>
	<?php
};
?>
<div class="lrn-onboarding" data-state="<?php echo $is_all_done ? 'complete' : 'active'; ?>" data-percent="<?php echo esc_attr( (string) $percent ); ?>">
	<div class="lrn-onboarding__header">
		<div class="lrn-onboarding__heading">
			<h2 class="lrn-onboarding__title">
				<?php
				printf(
					/* translators: %s: admin first name. */
					esc_html__( 'Welcome to Learnomy, %s', 'learnomy' ),
					esc_html( $first_name )
				);
				?>
			</h2>
			<p class="lrn-onboarding__subtitle">
				<?php esc_html_e( 'Finish these steps to launch your learning site.', 'learnomy' ); ?>
			</p>
		</div>
		<div class="lrn-onboarding__actions">
			<button
				type="button"
				class="lrn-onboarding__toggle lrn-onboarding__ghost-btn"
				aria-label="<?php esc_attr_e( 'Collapse guide', 'learnomy' ); ?>"
				aria-expanded="true"
			>
				<i data-lucide="chevron-up" aria-hidden="true"></i>
			</button>
			<button
				type="button"
				class="lrn-onboarding__dismiss lrn-onboarding__ghost-btn"
				aria-label="<?php esc_attr_e( 'Dismiss guide', 'learnomy' ); ?>"
			>
				<i data-lucide="x" aria-hidden="true"></i>
				<span><?php esc_html_e( 'Dismiss', 'learnomy' ); ?></span>
			</button>
		</div>
	</div>

	<div class="lrn-onboarding__progress">
		<div class="lrn-onboarding__progress-label">
			<?php
			printf(
				/* translators: 1: completed step count, 2: total step count, 3: percent. */
				esc_html__( '%1$d of %2$d complete · %3$d%%', 'learnomy' ),
				(int) $done,
				(int) $total,
				(int) $percent
			);
			?>
		</div>
		<div class="lrn-onboarding__progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>">
			<span class="lrn-onboarding__progress-fill" style="width: <?php echo esc_attr( (string) $percent ); ?>%"></span>
		</div>
	</div>

	<div class="lrn-onboarding__body">
		<?php if ( ! empty( $completed_steps ) ) : ?>
			<details class="lrn-onboarding__completed-group">
				<summary class="lrn-onboarding__completed-summary">
					<i data-lucide="check-circle-2" aria-hidden="true"></i>
					<?php
					printf(
						/* translators: %d: number of completed steps. */
						esc_html( _n( '%d completed step', '%d completed steps', count( $completed_steps ), 'learnomy' ) ),
						(int) count( $completed_steps )
					);
					?>
				</summary>
				<div class="lrn-onboarding__steps lrn-onboarding__steps--completed">
					<?php foreach ( $completed_steps as $completed_id => $completed_step ) : ?>
						<?php $render_card( $completed_id, $completed_step, 'completed' ); ?>
					<?php endforeach; ?>
				</div>
			</details>
		<?php endif; ?>

		<?php if ( ! empty( $active_steps ) ) : ?>
			<div class="lrn-onboarding__steps">
				<?php foreach ( $active_steps as $active_id => $active_step ) : ?>
					<?php $render_card( $active_id, $active_step, $resolved[ $active_id ] ?? 'pending' ); ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $is_all_done ) : ?>
			<div class="lrn-onboarding__all-done">
				<i data-lucide="party-popper" aria-hidden="true"></i>
				<div>
					<strong><?php esc_html_e( "You're all set!", 'learnomy' ); ?></strong>
					<span><?php esc_html_e( 'Every step is complete. This guide will auto-dismiss shortly.', 'learnomy' ); ?></span>
				</div>
			</div>
		<?php endif; ?>

		<?php
		/*
		 * No demo-removal card here.
		 *
		 * The guide used to append its own "Demo content is currently loaded"
		 * card with a Remove Demo Content button — directly beneath the
		 * `remove_demo` checklist step, which already offers that exact action.
		 * With the standalone demo-alert widget below the guide, the site owner
		 * was offered the same irreversible action THREE times inside a single
		 * viewport, two of them with an identical label 147px apart
		 * (Basecamp 10128600685).
		 *
		 * One control at a time: the `remove_demo` checklist step owns the
		 * action while it is actionable, and Free_Dashboard_Widgets hides the
		 * demo-alert widget for exactly that window. $demo_seeded is still
		 * resolved by the caller because the step's completion rule reads the
		 * same state.
		 */
		?>
	</div>
</div>
