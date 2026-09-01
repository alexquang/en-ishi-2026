<?php
/**
 * Single course — enroll / purchase card (shared partial).
 *
 * Extracted byte-identical from course/classic.php. Renders the inner content
 * of the sidebar purchase card: preview media, price, the Enroll / Buy / Sign-up
 * CTA (all enrolled and non-enrolled branches), wishlist, coupon, membership
 * note, money-back guarantee, "this course includes" list, and the enrolled
 * "Start / Continue" button.
 *
 * The caller owns the wrapper element. classic.php wraps this in
 * `<aside class="lrn-single-course__sidebar"><div class="lrn-sidebar-card lrn-card">`;
 * other variants embed the same markup inside a hero or a utility rail.
 *
 * Inherits the single-course template scope ($course, $is_enrolled,
 * $original_price, $sale_price, $has_sale, $enroll_url, $continue_url,
 * $preview_video, $thumbnail_url, $duration_label, $lesson_count, $quiz_count,
 * $is_wishlisted, $member_can_claim, $current_price, ...).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// Resolved once, up here, because it is read by three separate branches lower
// down — the price line, the CTA, and the includes list. Defining it inside the
// price block meant a render path that skipped that block left it undefined,
// and `empty()` would quietly treat an invite-only course as a normal one.
$lrn_invite_only = \Learnomy\Models\Course::is_invite_only( $course );
?>
<?php if ( ! empty( $is_owner ) && empty( $is_enrolled ) ) : ?>
	<?php
	// Owner sidebar — the course author, a co-instructor, or an admin must NOT
	// be shown the price + "Buy Now" + coupon + money-back block for their own
	// course (that was the "single course UI broken for the instructor" bug).
	// Show preview media + a manage / preview card instead.
	//
	// But an OWNER who is ALSO enrolled as a student here (a multi-course
	// instructor or an admin taking this course) must get the STUDENT sidebar —
	// their progress + certificate CTA — not "Edit Course". `is_owner` is true
	// for any manage_options admin, so without the enrolment guard a certified
	// student who happens to hold the instructor/admin role saw "You're the
	// instructor" and lost their certificate link (Basecamp 10087807524).
	?>
	<?php if ( ! empty( $thumbnail_url ) ) : ?>
		<div class="lrn-sidebar-card__preview">
			<img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="<?php echo esc_attr( $course->title ); ?>" loading="lazy">
		</div>
	<?php endif; ?>
	<div class="lrn-sidebar-card__body">
		<p class="lrn-sidebar-card__owner-note">
			<i data-lucide="badge-check" aria-hidden="true"></i>
			<?php
			// A site admin gets the manage view too, but is not the instructor —
			// say what's true for each (Basecamp 10101289218). Default to the
			// instructor line so an older caller that doesn't pass owner_reason
			// keeps its current wording.
			if ( isset( $owner_reason ) && 'site_admin' === $owner_reason ) {
				esc_html_e( 'You manage this site, so you can edit this course.', 'learnomy' );
			} else {
				esc_html_e( "You're the instructor of this course.", 'learnomy' );
			}
			?>
		</p>
		<?php if ( ! empty( $owner_edit_url ) ) : ?>
			<a href="<?php echo esc_url( $owner_edit_url ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-btn--full">
				<?php esc_html_e( 'Edit Course', 'learnomy' ); ?>
			</a>
		<?php endif; ?>
		<?php if ( ! empty( $continue_url ) ) : ?>
			<a href="<?php echo esc_url( $continue_url ); ?>" class="lrn-btn lrn-btn--ghost lrn-btn--full">
				<?php esc_html_e( 'Preview as student', 'learnomy' ); ?>
			</a>
		<?php endif; ?>
	</div>

<?php elseif ( empty( $has_access ) || ! empty( $member_can_claim ) ) : ?>
	<?php
	// This branch carries every "not in the course yet" state — the paywall AND
	// the membership claim.
	//
	// `$has_access` alone used to gate it, which made the "Activate via membership"
	// CTA further down unreachable exactly when it mattered. `$has_access` is
	// `is_enrolled || Permission_Engine::can('access_course')`, and that permission
	// check runs the SAME plan-coverage test as `$member_can_claim` — so a
	// plan-covered member always had `$has_access = true` and fell into the
	// enrolled branch, which showed "Start course". They could open the lessons,
	// but no `lrn_enrollments` row was ever created, so the course never appeared
	// in My Courses and carried no progress or certificate (Basecamp 10134139641).
	//
	// `$member_can_claim` is already "covered by a membership AND not enrolled"
	// (Single_Course_View_Data), so adding it here opens the branch for precisely
	// the entitled-but-unclaimed member and for nobody else. The pay-oriented
	// chrome inside (coupon box, money-back guarantee, "view membership plans")
	// is suppressed for them — they are not buying anything.
	//
	// Preview media is resolved by the view-data layer:
	// $preview_video holds a provider + embed URL (YouTube/Vimeo)
	// or a self-hosted file src, with an empty provider when the
	// course has no intro video. The wrapper is only emitted when
	// there is renderable media so we never leave a blank gray box.
	$has_preview_video = ( '' !== ( $preview_video['provider'] ?? '' ) );
	$has_preview_media = $has_preview_video || ! empty( $thumbnail_url );
	?>
	<?php if ( $has_preview_media ) : ?>
		<div class="lrn-sidebar-card__preview">
			<?php if ( 'youtube' === $preview_video['provider'] || 'vimeo' === $preview_video['provider'] ) : ?>
				<iframe
					src="<?php echo esc_url( $preview_video['embed_url'] ); ?>"
					class="lrn-course-preview-video"
					title="<?php echo esc_attr( $course->title ); ?>"
					loading="lazy"
					allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
					allowfullscreen
				></iframe>
			<?php elseif ( 'html5' === $preview_video['provider'] ) : ?>
				<video class="lrn-course-preview-video" playsinline controls preload="metadata">
					<source src="<?php echo esc_url( $preview_video['src'] ); ?>">
				</video>
			<?php elseif ( $thumbnail_url ) : ?>
				<img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="<?php echo esc_attr( $course->title ); ?>" loading="lazy">
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<!-- Price -->
	<div class="lrn-sidebar-card__body">
		<div class="lrn-sidebar-card__price">
			<?php
			/*
			 * An invite-only course is not sold anywhere, so "Sold externally"
			 * invents a transaction that does not exist and leaves the visitor
			 * looking for a shop. Say who lets them in instead. Both models share
			 * access_type=closed; only the wording differs.
			 */
			?>
			<?php if ( $lrn_invite_only ) : ?>
				<span class="lrn-sidebar-card__price-note"><?php esc_html_e( 'Invite only', 'learnomy' ); ?></span>
			<?php elseif ( \Learnomy\Models\Course::is_closed( $course ) ) : ?>
				<span class="lrn-sidebar-card__price-note"><?php esc_html_e( 'Sold externally', 'learnomy' ); ?></span>
			<?php elseif ( \Learnomy\Models\Course::is_members_only( $course ) ) : ?>
				<span class="lrn-sidebar-card__price-current"><?php esc_html_e( 'Members only', 'learnomy' ); ?></span>
			<?php elseif ( \Learnomy\Models\Course::is_free( $course ) || $original_price <= 0 ) : ?>
				<span class="lrn-sidebar-card__price-current"><?php esc_html_e( 'Free', 'learnomy' ); ?></span>
			<?php elseif ( $has_sale ) : ?>
				<span class="lrn-sidebar-card__price-current"><?php echo esc_html( \Learnomy\format_price( $sale_price ) ); ?>
				<?php
				if ( ! empty( $price_suffix ) ) :
					?>
					<span class="lrn-sidebar-card__price-interval"><?php echo esc_html( $price_suffix ); ?></span><?php endif; ?></span>
				<span class="lrn-sidebar-card__price-original"><?php echo esc_html( \Learnomy\format_price( $original_price ) ); ?></span>
				<?php
				$discount_pct = round( ( ( $original_price - $sale_price ) / $original_price ) * 100 );
				?>
				<?php /* translators: %s: discount percentage, e.g. "20" */ ?>
				<span class="lrn-badge lrn-badge--green"><?php echo esc_html( sprintf( __( '%s%% off', 'learnomy' ), $discount_pct ) ); ?></span>
			<?php else : ?>
				<span class="lrn-sidebar-card__price-current"><?php echo esc_html( \Learnomy\format_price( $original_price ) ); ?>
				<?php
				if ( ! empty( $price_suffix ) ) :
					?>
					<span class="lrn-sidebar-card__price-interval"><?php echo esc_html( $price_suffix ); ?></span><?php endif; ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $has_sale && ! empty( $course->sale_ends ) ) : ?>
			<p class="lrn-sidebar-card__sale-timer">
				<?php
				printf(
					/* translators: %s: sale end date */
					esc_html__( 'Sale ends %s', 'learnomy' ),
					esc_html( date_i18n( get_option( 'date_format' ), strtotime( $course->sale_ends ) ) )
				);
				?>
			</p>
		<?php endif; ?>

		<!-- CTA Button -->
		<?php
		// One resolver decides the verb and its destination for every surface;
		// this card renders it. The sticky bar used to mirror the branch tree
		// below by hand, kept in step by a comment asking the next reader to
		// remember - which is how a guest on a members-only course ended up
		// invited to "Sign up to enroll" under a "Members only" price
		// (Basecamp 10184318336, 10184459141).
		//
		// Only the pre-enrolment context is passed. This whole block is inside
		// `empty( $has_access ) || ! empty( $member_can_claim )`, and the
		// resolver's start/continue/complete branch needs the exact complement
		// of that (`has_access && ! member_can_claim`) - so it cannot be reached
		// from here, and progress/continue_url would be noise pretending to
		// matter. The enrolled student's button is rendered further down, from
		// the other arm of this same conditional.

		// One course URL for both the sign-up redirect the resolver builds and
		// the "log in" link beside it, so a guest who picks the second link
		// cannot land somewhere the first would not have taken them.
		$lrn_course_url = \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) );

		$lrn_cta = \Learnomy\Models\Course::cta_for_viewer(
			$course,
			get_current_user_id(),
			array(
				'has_access'       => ! empty( $has_access ),
				'member_can_claim' => ! empty( $member_can_claim ),
				'enroll_url'       => (string) $enroll_url,
				'course_url'       => $lrn_course_url,
			)
		);

		/**
		 * Lets an extension replace the entire enrol CTA with a gate notice —
		 * e.g. a course delivered only through cohorts shows "join a cohort to
		 * enrol" instead of a "Buy Now" button that would dead-end at the
		 * enrollment gate (Basecamp #9981599855). Return a non-empty HTML string
		 * (the provider escapes it) to take over the CTA; return '' to keep the
		 * default Buy/Enroll/Sign-up buttons.
		 *
		 * @param string $gate    Replacement CTA HTML ('' = default CTA).
		 * @param object $course  Course row.
		 * @param int    $user_id Current user id (0 if logged out).
		 */
		$lrn_enroll_gate = (string) apply_filters( 'learnomy_course_enroll_gate', '', $course, get_current_user_id() );

		/*
		 * Can this visitor actually buy this course, here, right now?
		 *
		 * The transaction chrome below - the coupon field and the money-back
		 * guarantee - used to gate on `$original_price > 0`, which is the PRICE
		 * COLUMN and not the question. A course can carry a price for accounting
		 * reasons and have no checkout at all, and then the sidebar prices it,
		 * offers a discount code and promises a refund while presenting no way
		 * to pay: measured on a cohort-gated $49 course, where the only CTA was
		 * "View cohort to join" (Basecamp 10184316845). The money-back line is
		 * the worst of the three - a refund promise on a transaction that cannot
		 * happen.
		 *
		 * Four things make an on-site purchase impossible, and every one of them
		 * is already decided above:
		 *   - no price to charge
		 *   - the visitor's membership already covers it (they are not buying)
		 *   - the course is `closed` / `invite_only`, which have no checkout
		 *   - an extension took over the CTA via `learnomy_course_enroll_gate`,
		 *     which is exactly what a cohort-only course does
		 *
		 * Asking once, here, is what stops the next block of pay-oriented chrome
		 * inheriting the price column's opinion again.
		 */
		$lrn_can_buy_here = $original_price > 0
			&& empty( $member_can_claim )
			&& ! \Learnomy\Models\Course::is_closed( $course )
			&& '' === $lrn_enroll_gate;
		?>
		<?php if ( \Learnomy\Models\Course::is_closed( $course ) ) : ?>
			<?php
			// Closed course: no on-site checkout. Show the optional custom
			// external button, else a plain "managed externally" note.
			//
			// Invite-only skips the button branch entirely. The pricing screen
			// gives it no URL field, but a course switched FROM closed TO
			// invite-only keeps the old setting, and this branch is evaluated
			// FIRST — so the stale URL would beat the invite-only note below and
			// send the visitor off-site to enrol in a course whose whole point is
			// that only the owner can enrol them.
			$lrn_closed_url   = $lrn_invite_only
				? ''
				: (string) \Learnomy\Models\Course::get_setting( (int) $course->id, 'closed_cta_url', '' );
			$lrn_closed_label = (string) \Learnomy\Models\Course::get_setting( (int) $course->id, 'closed_cta_label', '' );
			if ( '' !== $lrn_closed_url ) {
				$lrn_closed_html = '<a class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-btn--full" href="' . esc_url( $lrn_closed_url ) . '" target="_blank" rel="noopener">'
					. esc_html( '' !== $lrn_closed_label ? $lrn_closed_label : __( 'Enroll externally', 'learnomy' ) )
					. '</a>';
			} elseif ( $lrn_invite_only ) {
				// Names no role on purpose. This string ships to every kind of
				// site we have: a company where an HR lead assigns training, a
				// school where a teacher does, a solo creator who IS the whole
				// organisation. "Your administrator" was true on exactly one of
				// those and invented a person on the others, sending the visitor
				// looking for someone who does not exist. Site owners who DO have
				// a name and a contact route can say so through the
				// `learnomy_closed_cta` filter below — that is the seam for
				// local knowledge, and the default's job is to be true everywhere.
				$lrn_closed_html = '<p class="lrn-sidebar-card__external-note">' . esc_html__( 'Enrollment is by invitation.', 'learnomy' ) . '</p>';
			} else {
				$lrn_closed_html = '<p class="lrn-sidebar-card__external-note">' . esc_html__( 'Enrollment is managed externally.', 'learnomy' ) . '</p>';
			}
			/**
			 * Override the closed-course CTA HTML (Basecamp #10052638309).
			 *
			 * @param string $html    Default CTA HTML (button or note).
			 * @param object $course  Course row.
			 * @param int    $user_id Current user id (0 if logged out).
			 */
			echo apply_filters( 'learnomy_closed_cta', $lrn_closed_html, $course, get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_url/esc_html; filter provider owns any override.
			?>
		<?php elseif ( '' !== $lrn_enroll_gate ) : ?>
			<?php echo $lrn_enroll_gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the filter provider owns escaping. ?>
		<?php elseif ( is_user_logged_in() ) : ?>
			<?php if ( ! empty( $member_can_claim ) ) : ?>
				<?php
				// Viewer holds an active membership that covers this
				// course. Offer a no-payment claim — the same
				// $enroll_url action resolves to source=membership in
				// the router (resolve_enroll_source).
				?>
				<a href="<?php echo esc_url( $lrn_cta['url'] ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-btn--full">
					<?php echo esc_html( $lrn_cta['label'] ); ?>
				</a>
				<p class="lrn-sidebar-card__membership-note">
					<?php esc_html_e( 'Included with your membership.', 'learnomy' ); ?>
				</p>
			<?php elseif ( \Learnomy\Models\Course::is_members_only( $course ) ) : ?>
				<?php
				// Members-only course, viewer has no active membership.
				// "Enroll Now" would land them on /membership/ via the
				// router's resolve_enroll_source() — confusing because the
				// price label said "Free". Send them straight to the
				// membership pricing page with an honest CTA label.
				?>
				<a href="<?php echo esc_url( $lrn_cta['url'] ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-btn--full">
					<?php echo esc_html( $lrn_cta['label'] ); ?>
				</a>
				<p class="lrn-sidebar-card__membership-note">
					<?php esc_html_e( 'This course requires an active membership.', 'learnomy' ); ?>
				</p>
			<?php else : ?>
				<a href="<?php echo esc_url( $lrn_cta['url'] ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-btn--full">
					<?php echo esc_html( $lrn_cta['label'] ); ?>
				</a>
			<?php endif; ?>
		<?php else : ?>
			<?php
			// J-01.1/.2/.3 fix — logged-out customer on a paid (or free) course.
			// Previously the CTA used wp_login_url( get_permalink() ) which:
			// (a) routed to /wp-login.php (no register link visible — WP
			// users_can_register is OFF because Learnomy gates registration
			// its own way), AND
			// (b) get_permalink() returns false / home_url() for Learnomy's
			// custom-table courses, so redirect_to fell back to the
			// front page (or worse: /hello-world/ on a fresh install).
			// New behavior: primary CTA routes to Learnomy's /register/student/
			// with the actual course URL as redirect_to. Secondary "log in"
			// link below for returning customers. Both the verb and that
			// redirect now come from the resolver — a members-only course reads
			// "Sign up to join" logged out as well as logged in, which is the
			// pairing that broke when each branch decided for itself
			// (Basecamp 10184318336).
			$lrn_login_url = wp_login_url( $lrn_course_url );
			?>
			<a href="<?php echo esc_url( $lrn_cta['url'] ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-btn--full">
				<?php echo esc_html( $lrn_cta['label'] ); ?>
			</a>
			<?php if ( \Learnomy\Models\Course::is_members_only( $course ) ) : ?>
				<p class="lrn-sidebar-card__membership-note">
					<?php esc_html_e( 'This course requires an active membership.', 'learnomy' ); ?>
				</p>
			<?php endif; ?>
			<p class="lrn-sidebar-card__login-alt">
				<?php
				printf(
					/* translators: %s: login link */
					esc_html__( 'Already have an account? %s', 'learnomy' ),
					'<a href="' . esc_url( $lrn_login_url ) . '">' . esc_html__( 'Log in', 'learnomy' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>

		<?php
		// Wishlist heart — same control, store, and usermeta key as
		// the catalog card (templates/partials/course-card.php). Only
		// rendered for logged-in viewers so a guest can't silently
		// write wishlist meta; the Interactivity store + REST route
		// are reused unchanged. $is_wishlisted comes from the
		// view-data resolver.
		//
		// Hidden once the viewer is enrolled: a wishlist is "courses I mean to
		// take", so on a course you already own the control offered a save the
		// server now refuses, right under the Continue Learning button.
		// Also hidden on an invite-only course. A wishlist means "I mean to take
		// this later", which presumes the viewer could enrol at some point. On a
		// course only an administrator can add them to, it is a save button
		// against an action that will never be theirs to take.
		if ( is_user_logged_in() && empty( $is_enrolled ) && empty( $lrn_invite_only ) ) :
			$action_icon  = 'heart';
			$action_label = ! empty( $is_wishlisted )
				? __( 'In your wishlist', 'learnomy' )
				: __( 'Add to wishlist', 'learnomy' );
			$action_aria  = ! empty( $is_wishlisted )
				? __( 'Remove from wishlist', 'learnomy' )
				: __( 'Add to wishlist', 'learnomy' );
			$action_class = 'lrn-wishlist-btn lrn-btn--full' . ( ! empty( $is_wishlisted ) ? ' lrn-wishlist-btn--active' : '' );
			$action_attrs = array(
				'data-course-id'      => (int) $course->id,
				'data-wishlisted'     => ! empty( $is_wishlisted ) ? '1' : '0',
				'data-wp-on--click'   => 'actions.toggleWishlist',
				'data-wp-interactive' => 'learnomy/wishlist',
				'aria-pressed'        => ! empty( $is_wishlisted ) ? 'true' : 'false',
			);
			include \Learnomy\template_path( 'partials/lrn-action.php' );
		endif;
		?>

		<?php
		/**
		 * Whether the sales page offers a coupon field at all.
		 *
		 * Kept on by default so no live site loses a control on upgrade, but
		 * filterable to false for owners who would rather collect codes at
		 * checkout - which is where most storefronts put them, and which stops
		 * an empty field advertising discounts to the majority of visitors who
		 * have no code (Basecamp 10184419149).
		 *
		 * @param bool   $show   Default true.
		 * @param object $course Course row.
		 */
		$lrn_show_coupon = (bool) apply_filters( 'learnomy_course_show_coupon_field', true, $course );
		?>
		<?php if ( $lrn_can_buy_here && $lrn_show_coupon ) : ?>
			<div class="lrn-sidebar-card__coupon">
				<label for="lrn-coupon-input" class="lrn-sidebar-card__coupon-label">
					<?php esc_html_e( 'Have a coupon code?', 'learnomy' ); ?>
				</label>
				<div class="lrn-sidebar-card__coupon-row">
					<input type="text" id="lrn-coupon-input" class="lrn-input" placeholder="<?php esc_attr_e( 'Enter code', 'learnomy' ); ?>">
					<button type="button" id="lrn-sidebar-apply-coupon" class="lrn-btn lrn-btn--secondary lrn-btn--sm"
						data-course-id="<?php echo esc_attr( $course->id ); ?>"
						data-rest-url="<?php echo esc_url( rest_url( 'learnomy/v1/' ) ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
						data-base-price="<?php echo esc_attr( isset( $current_price ) ? (float) $current_price : ( ! empty( $has_sale ) ? (float) $sale_price : (float) $original_price ) ); ?>"
						data-currency="<?php echo esc_attr( \Learnomy\currency_symbol() ); ?>"
						><?php esc_html_e( 'Apply', 'learnomy' ); ?></button>
				</div>
				<p id="lrn-sidebar-coupon-message" class="lrn-sidebar-card__coupon-message" hidden></p>
				<?php // Repriced total shown once a coupon validates (card 10062796589). ?>
				<p id="lrn-sidebar-coupon-price" class="lrn-sidebar-card__coupon-price" role="status" hidden></p>
			</div>
		<?php endif; ?>

		<?php
		// Don't sell a membership to somebody who already holds one that covers
		// this course — and don't sell one at all when the site is not selling
		// them: with the membership module off the pricing route does not
		// exist, so this button was a link to a 404 on the page whose job is
		// to sell the course.
		?>
		<?php if ( \Learnomy\Models\Course::has_member_path( $course ) && empty( $member_can_claim ) && \Learnomy\membership_on_offer() ) : ?>
			<div class="lrn-sidebar-card__membership-cta">
				<p class="lrn-sidebar-card__membership-text">
					<?php esc_html_e( 'This course is included with a membership plan.', 'learnomy' ); ?>
				</p>
				<a href="<?php echo esc_url( \Learnomy\route_url( 'membership-pricing' ) ); ?>" class="lrn-btn lrn-btn--ghost lrn-btn--full">
					<?php esc_html_e( 'View Membership Plans', 'learnomy' ); ?>
				</a>
			</div>
		<?php endif; ?>

		<?php
		// No purchase, nothing to refund — the guarantee is meaningless to a member
		// activating a course their plan already includes.
		$refund_days = (int) ( get_option( 'learnomy_settings', array() )['refund_days'] ?? 30 );
		if ( $refund_days > 0 && $lrn_can_buy_here ) :
			?>
			<p class="lrn-sidebar-card__guarantee">
				<i data-lucide="shield" aria-hidden="true"></i>
				<?php
				/* translators: %d: number of days for refund guarantee */
				echo esc_html( sprintf( __( '%d-day money-back guarantee', 'learnomy' ), $refund_days ) );
				?>
			</p>
		<?php endif; ?>

		<!-- Course Includes -->
		<div class="lrn-sidebar-card__includes">
			<h4 class="lrn-sidebar-card__includes-title"><?php esc_html_e( 'This course includes:', 'learnomy' ); ?></h4>
			<ul class="lrn-sidebar-card__includes-list">
				<?php if ( $duration_label ) : ?>
					<li>
						<i data-lucide="clock" aria-hidden="true"></i>
						<?php echo esc_html( $duration_label ); ?> <?php esc_html_e( 'of content', 'learnomy' ); ?>
					</li>
				<?php endif; ?>
				<?php
				// A feature list must not advertise "0 lessons" as something
				// the buyer is getting (Basecamp 10184419301). Nothing is not a
				// feature; the row is omitted and the curriculum's empty state
				// tells the truth instead.
				?>
				<?php if ( $lesson_count > 0 ) : ?>
					<li>
						<i data-lucide="file-text" aria-hidden="true"></i>
						<?php
						/* translators: %d: number of lessons */
						echo esc_html( sprintf( _n( '%d lesson', '%d lessons', $lesson_count, 'learnomy' ), $lesson_count ) );
						?>
					</li>
				<?php endif; ?>
				<?php if ( $quiz_count > 0 ) : ?>
					<li>
						<i data-lucide="help-circle" aria-hidden="true"></i>
						<?php
						/* translators: %d: number of quizzes */
						echo esc_html( sprintf( _n( '%d quiz', '%d quizzes', $quiz_count, 'learnomy' ), $quiz_count ) );
						?>
					</li>
				<?php endif; ?>
				<li>
					<i data-lucide="award" aria-hidden="true"></i>
					<?php esc_html_e( 'Certificate of completion', 'learnomy' ); ?>
				</li>
				<li>
					<i data-lucide="smartphone" aria-hidden="true"></i>
					<?php esc_html_e( 'Access on all devices', 'learnomy' ); ?>
				</li>
				<?php
				/*
				 * "Lifetime access" is a promise about what a PURCHASE buys, so
				 * the line has to follow the pricing model rather than be printed
				 * for everyone who is not invite-only. It was appearing on a
				 * 30-day fixed-term course (contradicting the duration the owner
				 * had just configured), on a monthly subscription that ends when
				 * billing stops, on a members-only course that ends with the
				 * membership, and on a closed course that is not sold here at all
				 * (Basecamp 10184318317). The invite-only carve-out had the right
				 * instinct and the wrong scope. Course::access_duration_note()
				 * owns the answer for every model and returns '' when the honest
				 * move is to say nothing.
				 */
				$lrn_access_note = \Learnomy\Models\Course::access_duration_note( $course );
				?>
				<?php if ( '' !== $lrn_access_note ) : ?>
					<li>
						<i data-lucide="refresh-cw" aria-hidden="true"></i>
						<?php echo esc_html( $lrn_access_note ); ?>
					</li>
				<?php endif; ?>
			</ul>
		</div>
	</div>

<?php else : ?>
	<!-- Enrolled sidebar -->
	<?php
	// Three CTA states, gated on the authoritative completion flag:
	// - complete            -> "Go to My Courses" (continue_url routes there;
	// a finished course must not relabel "Continue Learning" and restart at
	// lesson 1),
	// - enrolled, 0 progress -> "Start course" (J-01.11: a fresh buyer has no
	// progress to continue from),
	// - enrolled, in progress -> "Continue Learning".
	$lrn_enrolled_pct = isset( $enrollment->progress_pct ) ? (int) $enrollment->progress_pct : 0;
	$lrn_enrolled_cta = ! empty( $is_course_complete )
		? __( 'Go to My Courses', 'learnomy' )
		: ( 0 === $lrn_enrolled_pct
			? __( 'Start course', 'learnomy' )
			: __( 'Continue learning', 'learnomy' ) );
	?>
	<div class="lrn-sidebar-card__body">
		<a href="<?php echo esc_url( $continue_url ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-btn--full">
			<?php echo esc_html( $lrn_enrolled_cta ); ?>
		</a>
	</div>
<?php endif; ?>

<?php
ob_start();

/**
 * Fires at the bottom of the single-course sidebar card, after the enrol/manage
 * CTAs (inside the card). Integrations can add a card action here — e.g. an
 * "Open the community" button.
 *
 * Buffered, and the card body is emitted only when a listener actually printed
 * something. `has_action()` answers "is anyone listening?", which is not the
 * same question: a listener that renders for members and stays quiet for
 * everyone else is listening on every request, so the wrapper was drawn - and
 * its padding with it - on every course a visitor could not see a community
 * for. Asking the output rather than the registration is what makes the empty
 * case actually empty.
 *
 * @since 1.4.1
 *
 * @param object $course Current course row.
 */
do_action( 'learnomy_course_sidebar_card_footer', $course );

$lrn_card_footer = trim( (string) ob_get_clean() );

if ( '' !== $lrn_card_footer ) :
	?>
	<div class="lrn-sidebar-card__body">
		<?php
		// Listener output, already escaped by whoever produced it.
		echo $lrn_card_footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</div>
<?php endif; ?>

<?php
// Mobile-only sticky buy bar (price + primary CTA pinned to the bottom of the
// viewport). Rendered once here because the enroll card is included once per
// single-course page; it is hidden on desktop via CSS.
require \Learnomy\template_path( 'course/partials/sticky-buy-bar.php' );
?>
