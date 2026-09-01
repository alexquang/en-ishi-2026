<?php
/**
 * Admin membership plan editor.
 *
 * Two-column layout: main (title, description, courses) + sidebar
 * (pricing, trial, status). Submits via AJAX to
 * `learnomy_save_membership_plan` so the page stays on the editor after
 * save and can surface a toast.
 *
 * @package Learnomy
 * @var object|null $plan          Plan object when editing, null when creating.
 * @var object[]   $assigned_courses Courses currently linked to this plan.
 * @var bool       $is_edit        True when editing an existing plan.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;

$settings = get_option( 'learnomy_settings', array() );
$currency = ( is_array( $settings ) && ! empty( $settings['currency'] ) ) ? (string) $settings['currency'] : 'USD';

// Courses a plan can grant. Attach-target, so no trashed courses: a plan that
// includes one sells access to something students cannot see.
$all_courses = Course::selectable();

$page_title = $is_edit
	/* translators: %s: plan title */
	? sprintf( __( 'Edit Plan: %s', 'learnomy' ), $plan->title ?? '' )
	: __( 'Add New Membership Plan', 'learnomy' );

$pricing_mode = ( $is_edit && ( ! empty( $plan->price_monthly ) || ! empty( $plan->price_yearly ) ) ) ? 'dual' : 'single';

$features = array();
if ( $is_edit && ! empty( $plan->features_json ) ) {
	$decoded = json_decode( (string) $plan->features_json, true );
	if ( is_array( $decoded ) ) {
		$features = array_map( 'strval', $decoded );
	}
}
if ( empty( $features ) ) {
	$features = array( '' ); // render a single empty row.
}

$assigned_ids = array();
foreach ( $assigned_courses as $c ) {
	$assigned_ids[] = (int) $c->id;
}

$currencies = array( 'USD', 'EUR', 'GBP', 'INR', 'AUD', 'CAD', 'JPY', 'BRL' );
if ( ! in_array( $currency, $currencies, true ) ) {
	$currencies[] = $currency;
}

$intervals = array(
	'daily'   => __( 'Daily', 'learnomy' ),
	'weekly'  => __( 'Weekly', 'learnomy' ),
	'monthly' => __( 'Monthly', 'learnomy' ),
	'yearly'  => __( 'Yearly', 'learnomy' ),
);

// Preview lands on the pricing page, anchored at this plan's card.
//
// This used to append the plan slug to the pricing URL, producing
// /membership/{slug}/. There is no per-plan membership route — `membership-pricing`
// is a single page listing every plan — so that path matched no rewrite rule and
// the link 404'd for every plan on the site (Basecamp 10195368664). Appending to
// the result of route_url() also defeats the point of the registry, which is that
// a URL's shape is declared in one place.
//
// A fragment gets the owner to the right card without inventing a route: the card
// carries id="lrn-plan-{id}" (see templates/partials/membership-plan-card.php).
$preview_url = \Learnomy\route_url( 'membership-pricing' );
if ( $is_edit && ! empty( $plan->id ) ) {
	$preview_url .= '#lrn-plan-' . absint( $plan->id );
}

// Template pre-fills — Empty-state picker (A+.3) hands off to this editor via
// ?template=<slug>. Only applied on create; we never override existing values.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$template_key = ( ! $is_edit && isset( $_GET['template'] ) ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : '';
$tpl_defaults = array(
	'title'            => '',
	'description'      => '',
	'price'            => 0,
	'billing_interval' => 'monthly',
	'trial_days'       => 0,
	'course_limit'     => '',
	'is_featured'      => 0,
);
switch ( $template_key ) {
	case 'basic_monthly':
		$tpl_defaults = array_merge(
			$tpl_defaults,
			array(
				'title'            => __( 'Monthly Basic', 'learnomy' ),
				'description'      => __( 'Access up to five courses every month. Cancel any time.', 'learnomy' ),
				'price'            => 9,
				'billing_interval' => 'monthly',
				'trial_days'       => 14,
				'course_limit'     => 5,
			)
		);
		break;
	case 'yearly_premium':
		$tpl_defaults = array_merge(
			$tpl_defaults,
			array(
				'title'            => __( 'Yearly Premium', 'learnomy' ),
				'description'      => __( 'Unlimited access for a full year. Best value.', 'learnomy' ),
				'price'            => 79,
				'billing_interval' => 'yearly',
				'trial_days'       => 0,
				'course_limit'     => '',
				'is_featured'      => 1,
			)
		);
		break;
	case 'free_trial':
		$tpl_defaults = array_merge(
			$tpl_defaults,
			array(
				'title'            => __( 'Free Trial', 'learnomy' ),
				'description'      => __( 'Start with 14 days free, then $29/month.', 'learnomy' ),
				'price'            => 29,
				'billing_interval' => 'monthly',
				'trial_days'       => 14,
			)
		);
		break;
}

/**
 * For fields below we need to read either the plan (on edit) or the
 * template default (on create with ?template=). These helpers keep the
 * ternaries out of the markup so it stays readable.
 */
$tpl_value = static function ( string $key, $fallback = '' ) use ( $is_edit, $plan, $tpl_defaults ) {
	if ( $is_edit ) {
		return $plan->{$key} ?? $fallback;
	}
	return $tpl_defaults[ $key ] ?? $fallback;
};

// Overlay the pricing-mode default when a template is selected.
if ( ! $is_edit && '' !== $template_key ) {
	$pricing_mode = 'single';
}
?>
<div class="wrap learnomy-admin lrn-membership-edit">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title"><?php echo esc_html( $page_title ); ?></h1>
			<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener" class="lrn-action-link">
				<i data-lucide="external-link" aria-hidden="true"></i>
				<?php esc_html_e( 'Preview on pricing page', 'learnomy' ); ?>
			</a>
		</div>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-memberships' ) ); ?>" class="lrn-admin-btn">
			<i data-lucide="arrow-left" aria-hidden="true"></i>
			<?php esc_html_e( 'Back to Plans', 'learnomy' ); ?>
		</a>
	</div>
	<hr class="wp-header-end">

	<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="lrn-admin-card lrn-admin-alert lrn-admin-alert--success">
			<i data-lucide="check-circle" aria-hidden="true"></i>
			<span><?php esc_html_e( 'Plan saved successfully.', 'learnomy' ); ?></span>
		</div>
	<?php endif; ?>

	<form id="lrn-plan-form" class="lrn-plan-form" data-plan-id="<?php echo $is_edit ? absint( $plan->id ) : 0; ?>">
		<?php wp_nonce_field( 'learnomy_admin', 'lrn_nonce' ); ?>

		<div class="lrn-plan-form__layout">
			<!-- Main column — progressive disclosure via <details> accordions -->
			<div class="lrn-plan-form__main">
				<details class="lrn-accordion" open>
					<summary class="lrn-accordion__summary">
						<i data-lucide="file-text" class="lrn-icon--sm" aria-hidden="true"></i>
						<span class="lrn-accordion__title"><?php esc_html_e( 'Essentials', 'learnomy' ); ?></span>
						<span class="lrn-accordion__hint"><?php esc_html_e( 'Title, description, price, billing, trial, courses', 'learnomy' ); ?></span>
						<i data-lucide="chevron-down" class="lrn-accordion__chevron" aria-hidden="true"></i>
					</summary>
					<div class="lrn-accordion__body">
						<div class="lrn-form-field lrn-form-field--wide">
							<label for="lrn-plan-title">
								<?php esc_html_e( 'Plan Title', 'learnomy' ); ?>
								<span class="required" aria-hidden="true">*</span>
							</label>
							<input type="text" id="lrn-plan-title" name="title" class="large-text" required
									value="<?php echo esc_attr( (string) $tpl_value( 'title', '' ) ); ?>"
									placeholder="<?php esc_attr_e( 'e.g. Pro Membership', 'learnomy' ); ?>">
						</div>

						<div class="lrn-form-field lrn-form-field--wide">
							<label for="lrn-plan-description"><?php esc_html_e( 'Description', 'learnomy' ); ?></label>
							<textarea id="lrn-plan-description" name="description" rows="5" class="large-text"
										placeholder="<?php esc_attr_e( 'Describe the benefits, who this plan is for, and what subscribers get.', 'learnomy' ); ?>"><?php echo esc_textarea( (string) $tpl_value( 'description', '' ) ); ?></textarea>
						</div>

						<div class="lrn-form-field-row">
							<div class="lrn-form-field">
								<label for="lrn-plan-price"><?php esc_html_e( 'Price', 'learnomy' ); ?></label>
								<input type="number" id="lrn-plan-price" name="price" min="0" step="0.01"
										value="<?php echo esc_attr( (string) $tpl_value( 'price', 0 ) ); ?>">
							</div>
							<div class="lrn-form-field">
								<label for="lrn-plan-interval"><?php esc_html_e( 'Billing interval', 'learnomy' ); ?></label>
								<select id="lrn-plan-interval" name="billing_interval">
									<?php
									foreach ( $intervals as $value => $label ) :
										$selected_interval = (string) $tpl_value( 'billing_interval', 'monthly' );
										?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected_interval, $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="lrn-form-field">
								<label for="lrn-plan-trial-days"><?php esc_html_e( 'Trial days', 'learnomy' ); ?></label>
								<input type="number" id="lrn-plan-trial-days" name="trial_days" min="0" step="1"
										value="<?php echo esc_attr( (string) $tpl_value( 'trial_days', 0 ) ); ?>">
								<p class="lrn-form-help"><?php esc_html_e( '0 disables the free trial.', 'learnomy' ); ?></p>
							</div>
						</div>

						<div class="lrn-form-field">
							<span class="lrn-form-field__label"><?php esc_html_e( 'Pricing mode', 'learnomy' ); ?></span>
							<label class="lrn-radio-label">
								<input type="radio" name="pricing_mode" value="single" <?php checked( $pricing_mode, 'single' ); ?>>
								<?php esc_html_e( 'Single price', 'learnomy' ); ?>
							</label>
							<label class="lrn-radio-label">
								<input type="radio" name="pricing_mode" value="dual" <?php checked( $pricing_mode, 'dual' ); ?>>
								<?php esc_html_e( 'Monthly + Yearly', 'learnomy' ); ?>
							</label>
						</div>

						<div class="lrn-form-field" data-pricing-mode="dual">
							<label for="lrn-plan-price-monthly"><?php esc_html_e( 'Price monthly', 'learnomy' ); ?></label>
							<input type="number" id="lrn-plan-price-monthly" name="price_monthly" min="0" step="0.01"
									value="<?php echo $is_edit && isset( $plan->price_monthly ) ? esc_attr( (string) $plan->price_monthly ) : ''; ?>">
						</div>
						<div class="lrn-form-field" data-pricing-mode="dual">
							<label for="lrn-plan-price-yearly"><?php esc_html_e( 'Price yearly', 'learnomy' ); ?></label>
							<input type="number" id="lrn-plan-price-yearly" name="price_yearly" min="0" step="0.01"
									value="<?php echo $is_edit && isset( $plan->price_yearly ) ? esc_attr( (string) $plan->price_yearly ) : ''; ?>">
						</div>

						<div class="lrn-form-field lrn-form-field--wide">
							<label for="lrn-courses-search"><?php esc_html_e( 'Courses included', 'learnomy' ); ?></label>
							<input type="search" id="lrn-courses-search" class="regular-text"
									placeholder="<?php esc_attr_e( 'Search courses…', 'learnomy' ); ?>">
							<p class="lrn-form-help"><?php esc_html_e( 'Subscribers are auto-enrolled in every selected course.', 'learnomy' ); ?></p>
						</div>
						<div class="lrn-courses-picker" id="lrn-courses-picker">
							<?php
							foreach ( $all_courses as $course ) :
								$course_id = (int) $course->id;
								$checked   = in_array( $course_id, $assigned_ids, true );
								?>
								<label class="lrn-courses-picker__row" data-title="<?php echo esc_attr( strtolower( $course->title ) ); ?>">
									<input type="checkbox" name="course_ids[]" value="<?php echo esc_attr( (string) $course_id ); ?>" <?php checked( $checked ); ?>>
									<span class="lrn-courses-picker__title"><?php echo esc_html( $course->title ); ?></span>
									<span class="lrn-courses-picker__status lrn-status-badge lrn-status-badge--<?php echo esc_attr( \Learnomy\Admin\UX::status_variant( (string) $course->status ) ); ?>">
										<?php echo esc_html( \Learnomy\Admin\UX::status_label( (string) $course->status ) ); ?>
									</span>
								</label>
							<?php endforeach; ?>
							<?php if ( empty( $all_courses ) ) : ?>
								<p class="lrn-no-items"><?php esc_html_e( 'No courses available yet. Create a course first.', 'learnomy' ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				</details>

				<details class="lrn-accordion" open>
					<summary class="lrn-accordion__summary">
						<i data-lucide="image" class="lrn-icon--sm" aria-hidden="true"></i>
						<span class="lrn-accordion__title"><?php esc_html_e( 'Features & Branding', 'learnomy' ); ?></span>
						<span class="lrn-accordion__hint"><?php esc_html_e( 'Thumbnail, bullet list, highlight', 'learnomy' ); ?></span>
						<i data-lucide="chevron-down" class="lrn-accordion__chevron" aria-hidden="true"></i>
					</summary>
					<div class="lrn-accordion__body">
						<div class="lrn-form-field lrn-form-field--wide">
							<label for="lrn-plan-thumbnail"><?php esc_html_e( 'Thumbnail', 'learnomy' ); ?></label>
							<div class="lrn-media-row">
								<input type="url" id="lrn-plan-thumbnail" name="thumbnail_url" class="regular-text"
										value="<?php echo $is_edit ? esc_attr( $plan->thumbnail_url ?? '' ) : ''; ?>"
										placeholder="https://…">
								<button type="button" id="lrn-plan-thumbnail-pick" class="lrn-admin-btn lrn-admin-btn--ghost">
									<i data-lucide="upload" aria-hidden="true"></i>
									<?php esc_html_e( 'Choose image', 'learnomy' ); ?>
								</button>
							</div>
						</div>

						<div class="lrn-form-field lrn-form-field--wide">
							<label><?php esc_html_e( 'Features', 'learnomy' ); ?></label>
							<p class="lrn-form-help">
								<?php esc_html_e( 'Shown as the bullet list on the pricing page. One line per feature.', 'learnomy' ); ?>
							</p>
							<div class="lrn-features-list" id="lrn-features-list">
								<?php foreach ( $features as $feature ) : ?>
									<div class="lrn-features-row">
										<input type="text" name="features[]" class="regular-text" value="<?php echo esc_attr( $feature ); ?>"
												placeholder="<?php esc_attr_e( 'e.g. Unlimited access to all courses', 'learnomy' ); ?>">
										<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost lrn-features-remove" aria-label="<?php esc_attr_e( 'Remove feature', 'learnomy' ); ?>">
											<i data-lucide="trash-2" aria-hidden="true"></i>
										</button>
									</div>
								<?php endforeach; ?>
							</div>
							<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost" id="lrn-features-add">
								<i data-lucide="plus" aria-hidden="true"></i>
								<?php esc_html_e( 'Add feature', 'learnomy' ); ?>
							</button>
						</div>

						<div class="lrn-form-field">
							<label class="lrn-checkbox-label">
								<input type="checkbox" name="is_featured" value="1"
										<?php checked( (int) $tpl_value( 'is_featured', 0 ), 1 ); ?>>
								<?php esc_html_e( 'Highlight on pricing page', 'learnomy' ); ?>
							</label>
						</div>
					</div>
				</details>

				<details class="lrn-accordion">
					<summary class="lrn-accordion__summary">
						<i data-lucide="settings-2" class="lrn-icon--sm" aria-hidden="true"></i>
						<span class="lrn-accordion__title"><?php esc_html_e( 'Advanced', 'learnomy' ); ?></span>
						<span class="lrn-accordion__hint"><?php esc_html_e( 'Slug, course limit, status, currency', 'learnomy' ); ?></span>
						<i data-lucide="chevron-down" class="lrn-accordion__chevron" aria-hidden="true"></i>
					</summary>
					<div class="lrn-accordion__body">
						<div class="lrn-form-field lrn-form-field--wide">
							<label for="lrn-plan-slug"><?php esc_html_e( 'Slug', 'learnomy' ); ?></label>
							<input type="text" id="lrn-plan-slug" name="slug" class="regular-text"
									value="<?php echo $is_edit ? esc_attr( $plan->slug ?? '' ) : ''; ?>"
									placeholder="<?php esc_attr_e( 'auto-generated from title', 'learnomy' ); ?>">
							<p class="lrn-form-help"><?php esc_html_e( 'Used in the pricing page URL. Lowercase letters, numbers, and hyphens.', 'learnomy' ); ?></p>
						</div>

						<div class="lrn-form-field-row">
							<div class="lrn-form-field">
								<label for="lrn-plan-currency"><?php esc_html_e( 'Currency', 'learnomy' ); ?></label>
								<select id="lrn-plan-currency" name="currency">
									<?php
									foreach ( $currencies as $code ) :
										$selected_currency = $is_edit ? ( $plan->currency ?? $currency ) : $currency;
										?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $selected_currency, $code ); ?>>
											<?php echo esc_html( $code ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="lrn-form-field">
								<label for="lrn-plan-interval-count"><?php esc_html_e( 'Interval count', 'learnomy' ); ?></label>
								<input type="number" id="lrn-plan-interval-count" name="interval_count" min="1" step="1"
										value="<?php echo $is_edit ? esc_attr( (string) ( $plan->interval_count ?? 1 ) ) : '1'; ?>">
								<p class="lrn-form-help"><?php esc_html_e( 'e.g. Every 2 months.', 'learnomy' ); ?></p>
							</div>
							<div class="lrn-form-field">
								<label for="lrn-plan-course-limit"><?php esc_html_e( 'Course limit', 'learnomy' ); ?></label>
								<input type="number" id="lrn-plan-course-limit" name="course_limit" min="0" step="1"
										value="<?php echo esc_attr( (string) $tpl_value( 'course_limit', '' ) ); ?>"
										placeholder="<?php esc_attr_e( 'Unlimited', 'learnomy' ); ?>">
							</div>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-plan-status"><?php esc_html_e( 'Status', 'learnomy' ); ?></label>
							<select id="lrn-plan-status" name="status">
								<option value="active" <?php selected( $is_edit ? ( $plan->status ?? 'active' ) : 'active', 'active' ); ?>>
									<?php esc_html_e( 'Active', 'learnomy' ); ?>
								</option>
								<option value="inactive" <?php selected( $is_edit ? ( $plan->status ?? 'active' ) : 'active', 'inactive' ); ?>>
									<?php esc_html_e( 'Inactive', 'learnomy' ); ?>
								</option>
							</select>
						</div>
					</div>
				</details>


			</div>

			<!-- Sidebar — publish actions only, sticky on desktop -->
			<div class="lrn-plan-form__sidebar">
				<div class="lrn-admin-card lrn-plan-form__publish">
					<h3 class="lrn-admin-card__title">
						<i data-lucide="rocket" class="lrn-icon--sm" aria-hidden="true"></i>
						<?php esc_html_e( 'Publish', 'learnomy' ); ?>
					</h3>
					<div class="lrn-form-actions">
						<button type="submit" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-plan-save">
							<?php echo $is_edit ? esc_html__( 'Update Plan', 'learnomy' ) : esc_html__( 'Create Plan', 'learnomy' ); ?>
						</button>
						<button type="submit" class="lrn-admin-btn lrn-admin-btn--ghost" id="lrn-plan-save-draft" data-status="inactive">
							<?php esc_html_e( 'Save as Draft', 'learnomy' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
	</form>
	<?php
	/**
	 * Render extra panels below the membership-plan editor form (e.g. an
	 * integration's per-plan config card that saves over its own REST route).
	 * Fires with the plan id — 0 for a not-yet-saved new plan, so a listener
	 * can require a persisted plan before offering per-plan config.
	 *
	 * @param int $plan_id The plan being edited (0 when creating).
	 */
	do_action( 'learnomy_membership_plan_after_form', $lrn_plan_id ?? 0 );
	?>
</div>
