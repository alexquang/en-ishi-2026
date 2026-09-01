<?php
/**
 * Course card partial — reusable in grids and lists.
 *
 * Expects: $course (object) — row from lrn_courses.
 * Optional: $enrollment (object|null) — enrollment row if the current user is enrolled.
 *          Grid builders that render many cards should instead batch-resolve the
 *          whole page with Course::attach_viewer_enrollment() and let the card read
 *          $course->viewer_enrollment; a per-card $enrollment for the matching
 *          course still wins.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $course ) ) {
	return;
}

use Learnomy\Models\Category;
use Learnomy\Services\Resume;

$course_url    = \Learnomy\route_url( 'course', array( 'slug' => $course->slug ) );
$thumbnail_url = '';
if ( ! empty( $course->thumbnail_url ) ) {
	$thumbnail_url = $course->thumbnail_url;
} elseif ( ! empty( $course->thumbnail_id ) ) {
	$thumbnail_url = wp_get_attachment_image_url( (int) $course->thumbnail_id, 'medium' ) ?: '';
}
$title         = $course->title ?? '';
$rating        = isset( $course->avg_rating ) ? (float) $course->avg_rating : 0.0;
$review_count  = isset( $course->review_count ) ? (int) $course->review_count : 0;
$student_count = isset( $course->student_count ) ? (int) $course->student_count : 0;
// Course::headline_price() rather than the `price` column, so a recurring
// course reads its charge out of `recurring_amount` instead of the 0 sitting in
// `price` — the catalog card showed "Free" + "Enroll" on a $19/month course
// (Basecamp 10184318257). Same resolver the single-course page uses, so the two
// surfaces cannot disagree about what a course costs.
$price         = \Learnomy\Models\Course::headline_price( $course );
// Members-only courses carry a $0 price but are NOT free — surface a
// "Members only" affordance instead of mislabelling them "Free" (Basecamp
// #9957585141). Checked before $is_free so the price<=0 fallback can't win.
$is_members_only = \Learnomy\Models\Course::is_members_only( $course );
// Access granted externally: like members-only, the footer shows a LABEL rather
// than a price, so it is resolved up here with the others instead of only in
// the render branch — otherwise a closed course priced at 0 also picked up the
// green "free" class while rendering the word "External" (Basecamp 10149397436).
$is_closed      = \Learnomy\Models\Course::is_closed( $course );
$is_invite_only = \Learnomy\Models\Course::is_invite_only( $course );
$is_free        = ! $is_members_only && ! $is_closed && ( \Learnomy\Models\Course::is_free( $course ) || $price <= 0 );
$is_recurring   = \Learnomy\Models\Course::is_recurring( $course );
// Active-sale state, from the shared model helper so the card, the catalog and
// the single-course page all agree (Basecamp #9981514248: the card never showed
// the sale price). $sale_price is what the buyer pays; $price is struck through.
$is_on_sale   = \Learnomy\Models\Course::is_on_sale( $course );
$sale_price   = $is_on_sale ? (float) $course->sale_price : $price;
$price_suffix = '';
if ( $is_recurring ) {
	$price_suffix = 'year' === \Learnomy\Models\Course::recurring_interval( $course )
		? __( '/yr', 'learnomy' )
		: __( '/mo', 'learnomy' );
}
// The viewer's enrollment for THIS course, from whichever supply the includer
// used. Grid builders batch-resolve the page (Course::attach_viewer_enrollment)
// and stamp it on the row; single-card callers pass $enrollment directly. The
// course_id match is what keeps the two apart: the card is included in loops
// that reuse a shared $enrollment local (e.g. the My Courses tabs), and without
// it a leftover enrollment from a prior iteration would paint the wrong course's
// progress — the Wishlist tab showed purchased courses as 100% complete that way
// (Basecamp #9981514248).
// Covered by the viewer's membership and not yet claimed. Stamped for the whole
// grid by Course::attach_viewer_claimable(), so this costs the card nothing.
//
// Without it a member browsing the catalog saw "Members only" on every course
// their subscription already paid for - identical to what a stranger sees -
// while the course page told the same person "Activate via membership -
// included with your membership" (Basecamp 10207864281). The value they were
// already paying for was invisible at the moment they were choosing what to
// learn next.
$lrn_claimable = ! empty( $course->viewer_claimable );

$lrn_enrollment = ( isset( $enrollment->course_id ) && (int) $enrollment->course_id === (int) $course->id )
	? $enrollment
	: ( $course->viewer_enrollment ?? null );

$progress_pct = isset( $lrn_enrollment->progress_pct )
	? (int) $lrn_enrollment->progress_pct
	: null;

// Completion is a STATUS concept, not progress_pct >= 100: a percentage-threshold
// or manual completion sits at its real coverage (e.g. 33%). Judge it from the
// shared predicate so the label matches the Completed tab — otherwise a finished
// course shows "Continue" + a partial bar (Basecamp 10109147988).
$lrn_is_completed = $lrn_enrollment
	? \Learnomy\Models\Enrollment::is_completed( $lrn_enrollment )
	: false;

// Category name + instructor name/URL. The catalog view-data layer
// (Course::attach_card_meta) pre-resolves these for the whole grid in two
// batched queries and stamps them onto the row, so prefer those properties
// when present. The per-card lookups below are the fallback for cards
// rendered outside that builder (single course pages, dashboard widgets).
if ( isset( $course->category_name ) ) {
	$category_name = (string) $course->category_name;
} else {
	$category_name = '';
	if ( ! empty( $course->category_id ) ) {
		$cat = Category::find( (int) $course->category_id );
		if ( $cat ) {
			$category_name = $cat->name;
		}
	}
}

if ( isset( $course->instructor_name ) ) {
	$instructor_name = (string) $course->instructor_name;
	$instructor_url  = isset( $course->instructor_url ) ? (string) $course->instructor_url : '';
} else {
	$instructor_name = '';
	$instructor_url  = '';
	if ( ! empty( $course->author_id ) ) {
		$instructor = get_userdata( (int) $course->author_id );
		if ( $instructor ) {
			$instructor_name = $instructor->display_name;
			$instructor_url  = \Learnomy\route_url( 'instructor-profile', array( 'slug' => (string) $instructor->user_login ) );
		}
	}
}

// Wishlist state — $lrn_wishlist is expected to be set by the parent template.
// Falls back to loading from usermeta if the parent didn't set it.
if ( ! isset( $lrn_wishlist ) ) {
	$lrn_wishlist = \Learnomy\wishlist_ids();
}
$is_wishlisted = in_array( (int) $course->id, (array) $lrn_wishlist, true );

// "Save for later" makes no sense on a course you already have — offering it
// invited a save the server now refuses, and on the enrolled card it sat next
// to a progress bar. $lrn_enrollment is already batch-resolved above by every
// grid that renders this card (catalog, category archive, instructor profile,
// dashboard tabs), so this costs no extra query.
$lrn_show_wishlist = is_user_logged_in() && null === $lrn_enrollment;
?>
<article class="lrn-course-card">
	<a href="<?php echo esc_url( $course_url ); ?>" class="lrn-course-card__thumbnail-link" tabindex="-1" aria-hidden="true">
		<?php if ( $thumbnail_url ) : ?>
			<div class="lrn-course-card__thumbnail">
				<img src="<?php echo esc_url( $thumbnail_url ); ?>"
					alt="<?php echo esc_attr( $title ); ?>"
					loading="lazy" width="320" height="180">
			</div>
		<?php else : ?>
			<div class="lrn-course-card__thumbnail lrn-course-card__thumbnail--placeholder" aria-hidden="true">
				<span class="lrn-course-card__thumbnail-initial">
					<?php echo esc_html( mb_strtoupper( mb_substr( $title ?: 'L', 0, 1 ) ) ); ?>
				</span>
			</div>
		<?php endif; ?>
	</a>

	<?php
	if ( $lrn_show_wishlist ) :
		$action_icon  = 'heart';
		$action_label = null;
		$action_title = null;
		$action_href  = null;
		$action_aria  = $is_wishlisted
			? __( 'Remove from wishlist', 'learnomy' )
			: __( 'Add to wishlist', 'learnomy' );
		$action_class = 'lrn-wishlist-btn' . ( $is_wishlisted ? ' lrn-wishlist-btn--active' : '' );
		$action_attrs = [
			'data-course-id'      => (int) $course->id,
			'data-wishlisted'     => $is_wishlisted ? '1' : '0',
			'data-wp-on--click'   => 'actions.toggleWishlist',
			'data-wp-interactive' => 'learnomy/wishlist',
			'aria-pressed'        => $is_wishlisted ? 'true' : 'false',
		];
		include \Learnomy\template_path( 'partials/lrn-action.php' );
	endif;
	?>

	<div class="lrn-course-card__info">
		<div class="lrn-course-card__badges">
			<?php if ( $category_name ) : ?>
				<span class="lrn-badge lrn-badge--blue"><?php echo esc_html( $category_name ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $course->content_type_label ) ) : ?>
				<span class="lrn-badge lrn-badge--format">
					<i data-lucide="<?php echo esc_attr( $course->content_type_icon ); ?>" aria-hidden="true"></i>
					<?php echo esc_html( $course->content_type_label ); ?>
				</span>
			<?php endif; ?>
			<?php
			/**
			 * Fires inside the course card badge row so Surface A (sales
			 * page + catalog) and add-ons can attach status ribbons — e.g.
			 * "Featured", "New", "Bestseller" — without editing this
			 * partial. Subscribers should echo a single `.lrn-badge` span.
			 *
			 * @param object $course Course row from lrn_courses.
			 */
			do_action( 'learnomy_course_card_badges', $course );
			?>
		</div>

		<?php
		/**
		 * Per-card taxonomy / meta slot. Catalog Facets framework subscribers
		 * (and ad-hoc extensions) paint badges, breadcrumbs, or category
		 * lines here — between the title and the instructor row. Echo your
		 * own wrapped markup; the card supplies no default styling so
		 * subscribers control their own look.
		 *
		 * @param object $course Course row from lrn_courses.
		 */
		do_action( 'learnomy_course_card_meta', $course );
		?>

		<h3 class="lrn-course-card__title">
			<a href="<?php echo esc_url( $course_url ); ?>"><?php echo esc_html( $title ); ?></a>
		</h3>

		<?php if ( $instructor_name ) : ?>
			<?php if ( $instructor_url ) : ?>
				<a href="<?php echo esc_url( $instructor_url ); ?>" class="lrn-course-card__meta lrn-course-card__instructor-link"><?php echo esc_html( $instructor_name ); ?></a>
			<?php else : ?>
				<span class="lrn-course-card__meta"><?php echo esc_html( $instructor_name ); ?></span>
			<?php endif; ?>
		<?php endif; ?>

		<div class="lrn-course-card__stats">
			<?php
			$has_rating = $rating > 0;
			if ( $has_rating ) :
				?>
				<?php /* translators: %s: rating value, e.g. 4.5 */ ?>
				<span class="lrn-course-card__rating" aria-label="<?php echo esc_attr( sprintf( __( 'Rated %.1f out of 5', 'learnomy' ), $rating ) ); ?>">
					&#9733; <?php echo esc_html( number_format_i18n( $rating, 1 ) ); ?>
				</span>
				<span class="lrn-course-card__review-count">(<?php echo esc_html( $review_count ); ?>)</span>
			<?php endif; ?>

			<?php if ( $student_count > 0 ) : ?>
				<span class="lrn-course-card__students">
					<?php
					if ( $has_rating ) {
						echo '&middot; ';
					}
					printf(
						/* translators: %s: number of students */
						esc_html( _n( '%s student', '%s students', $student_count, 'learnomy' ) ),
						esc_html( number_format_i18n( $student_count ) )
					);
					?>
				</span>
			<?php endif; ?>
		</div>

		<div class="lrn-course-card__footer<?php echo null !== $progress_pct ? ' lrn-course-card__footer--enrolled' : ''; ?>">
			<?php if ( null !== $progress_pct ) : ?>
				<?php
				// Enrolled card (My Courses, or an in-progress course in the
				// catalog): the viewer already owns this course, so the price is
				// noise — surface progress + a "Continue/Review" affordance
				// instead. Drilling the card already opens the course.
				$percent = $progress_pct;
				include \Learnomy\template_path( 'partials/progress-bar.php' );
				// Continue / Start deep-link to the resume lesson (shared Resume
				// resolver); a finished course keeps the course page so "Review"
				// opens the whole curriculum, not one lesson.
				$resume_href = $lrn_is_completed
					? $course_url
					: Resume::url( get_current_user_id(), $course );
				?>
				<a class="lrn-course-card__resume" href="<?php echo esc_url( $resume_href ); ?>">
					<?php
					echo $lrn_is_completed
						? esc_html__( 'Review', 'learnomy' )
						: ( $progress_pct > 0 ? esc_html__( 'Continue', 'learnomy' ) : esc_html__( 'Start', 'learnomy' ) );
					?>
					<i data-lucide="arrow-right" aria-hidden="true"></i>
				</a>
			<?php else : ?>
				<?php
				// --label carries the shared "icon + word" treatment (inline-flex,
				// gap, smaller type, 14px glyph); --members / --external only add
				// their colour. Before this, "External" reused the bare price rule
				// and its Lucide glyph rendered at the 24px default.
				$lrn_price_classes = 'lrn-course-card__price';
				if ( $lrn_claimable ) {
					$lrn_price_classes .= ' lrn-course-card__price--label lrn-course-card__price--included';
				} elseif ( $is_closed ) {
					$lrn_price_classes .= ' lrn-course-card__price--label lrn-course-card__price--external';
				} elseif ( $is_members_only ) {
					$lrn_price_classes .= ' lrn-course-card__price--label lrn-course-card__price--members';
				} elseif ( $is_free ) {
					$lrn_price_classes .= ' lrn-course-card__price--free';
				}
				?>
				<span class="<?php echo esc_attr( $lrn_price_classes ); ?>">
					<?php if ( $lrn_claimable ) : ?>
						<?php
						// States what they HAVE, not what to do about it. A catalog
						// card footer is a price slot - the reader is scanning to
						// compare, not to act - so it answers the question that slot
						// asks ("what does this cost me?") with "nothing, you already
						// pay for it". The verb lives on the course page, where the
						// button is.
						?>
						<i data-lucide="crown" aria-hidden="true"></i>
						<?php esc_html_e( 'Included with your membership', 'learnomy' ); ?>
					<?php elseif ( $is_invite_only ) : ?>
						<?php
						// Same access rule as closed, deliberately different words.
						// "External" tells the browser to go and get this somewhere
						// else, which is the opposite of what happens here: the
						// owner adds them, on this site, and there is nowhere to go.
						?>
						<i data-lucide="mail" aria-hidden="true"></i>
						<?php esc_html_e( 'Invite only', 'learnomy' ); ?>
					<?php elseif ( $is_closed ) : ?>
						<i data-lucide="external-link" aria-hidden="true"></i>
						<?php esc_html_e( 'External', 'learnomy' ); ?>
					<?php elseif ( $is_members_only ) : ?>
						<i data-lucide="crown" aria-hidden="true"></i>
						<?php esc_html_e( 'Members only', 'learnomy' ); ?>
					<?php elseif ( $is_free ) : ?>
						<?php esc_html_e( 'Free', 'learnomy' ); ?>
					<?php elseif ( $is_on_sale ) : ?>
						<?php
						// On sale: the discounted price leads, the regular price is
						// struck through. Suffix is printed (not interleaved) so the
						// price + interval render with no gap ("$29.00/mo").
						echo '<span class="lrn-course-card__price-now">' . esc_html( \Learnomy\format_price( $sale_price ) );
						if ( ! empty( $price_suffix ) ) {
							printf(
								'<span class="lrn-course-card__price-interval">%s</span>',
								esc_html( $price_suffix )
							);
						}
						echo '</span>';
						printf(
							'<span class="lrn-course-card__price-original">%s</span>',
							esc_html( \Learnomy\format_price( $price ) )
						);
						?>
					<?php else : ?>
						<?php
						echo esc_html( \Learnomy\format_price( $price ) );
						// Suffix is printed (not echoed via interleaved tags) so the
						// price and its interval render with no gap: "$29.00/mo".
						if ( ! empty( $price_suffix ) ) {
							printf(
								'<span class="lrn-course-card__price-interval">%s</span>',
								esc_html( $price_suffix )
							);
						}
						?>
					<?php endif; ?>
				</span>

				<?php
				/*
				 * The action label (Basecamp 10171347913).
				 *
				 * An ENROLLED card has always had one — Start / Continue / Review
				 * — while a purchasable card showed a price and nothing else. So
				 * the course a visitor already owns invited them in and the one
				 * they might buy did not, which is backwards on a catalog whose
				 * job is to sell. The only other interactive things on the card
				 * were the title and a thumbnail anchor that is aria-hidden and
				 * tabindex="-1", so keyboard and screen-reader users had no
				 * labelled way in at all.
				 *
				 * Only the purchasable states get it. Invite only / External /
				 * Members only already say what is going on, and adding "Buy" to
				 * a course nobody can buy would be a CTA that dead-ends — the
				 * same mistake avoided on the quiz result screen.
				 *
				 * It links to the course page rather than straight to checkout,
				 * because enrolment decisions are made there and free courses
				 * still need the enrol confirmation.
				 */
				if ( ! $is_invite_only && ! $is_closed && ! $is_members_only ) :
					?>
					<a class="lrn-course-card__resume" href="<?php echo esc_url( $course_url ); ?>">
						<?php
						echo $is_free
							? esc_html__( 'Enroll', 'learnomy' )
							: esc_html__( 'Buy', 'learnomy' );
						?>
						<i data-lucide="arrow-right" aria-hidden="true"></i>
					</a>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
</article>
