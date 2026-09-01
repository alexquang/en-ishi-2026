<?php
/**
 * Review card partial.
 *
 * Expects:
 *   $review        (object)     — row from lrn_reviews with columns:
 *                                  user_id, rating, title, body, created_at
 *   $reviewer_map  (array|null) — optional WP_User[] keyed by user_id,
 *                                  pre-fetched by the caller to avoid N+1
 *                                  get_userdata() calls per card. Falls back
 *                                  to a single get_userdata() when not set so
 *                                  the partial still works stand-alone.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $review ) ) {
	return;
}

// Use the pre-fetched map when the caller supplies it (zero extra queries),
// otherwise fall back to the cached WP_User_Meta lookup.
$reviewer    = isset( $reviewer_map ) && is_array( $reviewer_map )
	? ( $reviewer_map[ (int) $review->user_id ] ?? null )
	: get_userdata( (int) $review->user_id );
$name        = $reviewer ? $reviewer->display_name : __( 'Anonymous', 'learnomy' );
$avatar_url  = $reviewer ? get_avatar_url( (int) $review->user_id, [ 'size' => 48 ] ) : '';
$rating      = (int) $review->rating;
$review_date = date_i18n( get_option( 'date_format' ), strtotime( $review->created_at ) );
?>
<article class="lrn-review-card lrn-card" data-author-id="<?php echo esc_attr( (int) $review->user_id ); ?>">
	<header class="lrn-review-card__header">
		<?php if ( $avatar_url ) : ?>
			<img class="lrn-review-card__avatar"
				src="<?php echo esc_url( $avatar_url ); ?>"
				alt="<?php echo esc_attr( $name ); ?>"
				width="48" height="48" loading="lazy">
		<?php endif; ?>

		<div class="lrn-review-card__meta">
			<strong class="lrn-review-card__name"><?php echo esc_html( $name ); ?></strong>
			<?php /* translators: %d: star rating number */ ?>
			<div class="lrn-review-card__stars" aria-label="<?php echo esc_attr( sprintf( __( '%d out of 5 stars', 'learnomy' ), $rating ) ); ?>">
				<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
					<span class="lrn-star <?php echo esc_attr( $i <= $rating ? 'lrn-star--filled' : 'lrn-star--empty' ); ?>" aria-hidden="true">&#9733;</span>
				<?php endfor; ?>
			</div>
		</div>

		<time class="lrn-review-card__date" datetime="<?php echo esc_attr( $review->created_at ); ?>">
			<?php echo esc_html( $review_date ); ?>
		</time>
	</header>

	<?php if ( ! empty( $review->title ) ) : ?>
		<h4 class="lrn-review-card__title"><?php echo esc_html( $review->title ); ?></h4>
	<?php endif; ?>

	<?php
	$lrn_review_body = $review->content ?? ( $review->body ?? '' );
	?>
	<?php if ( ! empty( $lrn_review_body ) ) : ?>
		<p class="lrn-review-card__body"><?php echo esc_html( $lrn_review_body ); ?></p>
	<?php endif; ?>

	<?php
	// Instructor reply — shown to everyone when present (builds trust:
	// students see the instructor engages with feedback). The write-side
	// form below only renders for the course owner / admin.
	if ( ! empty( $review->instructor_reply ) ) :
		?>
		<div class="lrn-review-card__reply">
			<span class="lrn-review-card__reply-label">
				<i data-lucide="corner-down-right" aria-hidden="true"></i>
				<?php printf( esc_html( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s response', 'learnomy' ) ), esc_html( \Learnomy\instructor_label() ) ); ?>
			</span>
			<p class="lrn-review-card__reply-body"><?php echo esc_html( $review->instructor_reply ); ?></p>
		</div>
	<?php elseif ( ! empty( $GLOBALS['lrn_can_reply_reviews'] ) ) : ?>
		<?php // J — instructor/admin reply affordance, only when no reply exists yet. ?>
		<form class="lrn-review-reply-form" data-review-id="<?php echo esc_attr( (int) $review->id ); ?>">
			<label class="lrn-sr-only" for="lrn-review-reply-<?php echo esc_attr( (int) $review->id ); ?>">
				<?php esc_html_e( 'Reply to this review', 'learnomy' ); ?>
			</label>
			<textarea
				id="lrn-review-reply-<?php echo esc_attr( (int) $review->id ); ?>"
				class="lrn-input lrn-input--textarea lrn-review-reply-form__input"
				name="reply"
				rows="2"
				maxlength="<?php echo esc_attr( (string) \Learnomy\Models\Review::MAX_REPLY_LENGTH ); ?>"
				placeholder="<?php esc_attr_e( 'Write a public response to this review.', 'learnomy' ); ?>"
				required></textarea>
			<div class="lrn-review-reply-form__alert" role="alert" hidden></div>
			<button type="submit" class="lrn-btn lrn-btn--secondary lrn-btn--sm lrn-review-reply-form__submit">
				<?php esc_html_e( 'Post response', 'learnomy' ); ?>
			</button>
		</form>
	<?php endif; ?>

	<?php
	// Report affordance — any logged-in member (except the review's own author)
	// can flag this review for a moderator. JS: assets/js/report.js.
	$lrn_report_user = get_current_user_id();
	if ( $lrn_report_user > 0 && $lrn_report_user !== (int) $review->user_id ) :
		?>
		<div class="lrn-review-card__report">
			<?php \Learnomy\report_button( 'review', (int) $review->id ); ?>
			<?php
			// Block affordance — hide this member's comments + reviews from my own
			// reads. Staff are unblockable (the API rejects it), so no button on an
			// administrator's review. JS: assets/js/block-user.js.
			if ( ! user_can( (int) $review->user_id, 'manage_options' ) ) :
				?>
				<button type="button" class="lrn-block-btn"
					data-user-id="<?php echo esc_attr( (int) $review->user_id ); ?>"
					data-user-name="<?php echo esc_attr( $name ); ?>">
					<i data-lucide="user-x" aria-hidden="true"></i>
					<span class="lrn-block-btn__label"><?php esc_html_e( 'Block', 'learnomy' ); ?></span>
				</button>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</article>
