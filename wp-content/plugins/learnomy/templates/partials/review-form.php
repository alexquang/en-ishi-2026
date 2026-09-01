<?php
/**
 * Write-a-review form for enrolled students.
 *
 * Posts to `POST /learnomy/v1/courses/<id>/reviews`. Optimistic UI is
 * driven by `assets/js/review-form.js`, which the template loader enqueues
 * on the `course` route only when can_user_review is true.
 *
 * Expects (from Single_Course_View_Data):
 *   $course           object  Course row.
 *   $can_user_review  bool    Logged in + enrolled + reviews_enabled.
 *   $user_review      object|null Existing review row (renders read-only when set).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $course ) ) {
	return;
}

// Existing review path — show the student their own review with a small
// "Thanks for reviewing this course." note. No edit affordance in v1.0;
// the PATCH endpoint exists but isn't customer-flow-blocking.
if ( ! empty( $user_review ) ) :
	$lrn_my_rating = max( 1, min( 5, (int) $user_review->rating ) );
	$lrn_my_date   = date_i18n( get_option( 'date_format' ), strtotime( $user_review->created_at ) );
	?>
	<div class="lrn-review-form lrn-review-form--posted lrn-card" data-course-id="<?php echo esc_attr( (int) $course->id ); ?>">
		<div class="lrn-review-form__posted-head">
			<strong><?php esc_html_e( 'Thanks for reviewing this course.', 'learnomy' ); ?></strong>
			<time datetime="<?php echo esc_attr( $user_review->created_at ); ?>" class="lrn-review-form__posted-date">
				<?php echo esc_html( $lrn_my_date ); ?>
			</time>
		</div>
		<div class="lrn-review-form__posted-stars" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: rating */ __( '%d out of 5 stars', 'learnomy' ), $lrn_my_rating ) ); ?>">
			<?php for ( $lrn_i = 1; $lrn_i <= 5; $lrn_i++ ) : ?>
				<span class="lrn-star <?php echo esc_attr( $lrn_i <= $lrn_my_rating ? 'lrn-star--filled' : 'lrn-star--empty' ); ?>" aria-hidden="true">&#9733;</span>
			<?php endfor; ?>
		</div>
		<?php if ( ! empty( $user_review->title ) ) : ?>
			<h4 class="lrn-review-form__posted-title"><?php echo esc_html( $user_review->title ); ?></h4>
		<?php endif; ?>
		<?php if ( ! empty( $user_review->content ) ) : ?>
			<p class="lrn-review-form__posted-body"><?php echo esc_html( $user_review->content ); ?></p>
		<?php endif; ?>
	</div>
	<?php
	return;
endif;

// No existing review — render the form.
?>
<form class="lrn-review-form lrn-card"
	id="lrn-review-form"
	data-course-id="<?php echo esc_attr( (int) $course->id ); ?>"
	novalidate>
	<h3 class="lrn-review-form__title">
		<?php esc_html_e( 'Write a review', 'learnomy' ); ?>
	</h3>
	<p class="lrn-review-form__subtitle">
		<?php esc_html_e( 'Share what you learned. Other students use your review when deciding whether to take this course.', 'learnomy' ); ?>
	</p>

	<div class="lrn-review-form__alert lrn-review-form__alert--error" id="lrn-review-form-error" role="alert" hidden></div>

	<fieldset class="lrn-review-form__rating">
		<legend><?php esc_html_e( 'Your rating', 'learnomy' ); ?> <span aria-hidden="true">*</span></legend>
		<div class="lrn-review-form__stars" role="radiogroup">
			<?php for ( $lrn_i = 1; $lrn_i <= 5; $lrn_i++ ) : ?>
				<label class="lrn-review-form__star-label">
					<input type="radio" name="rating" value="<?php echo esc_attr( $lrn_i ); ?>" required>
					<span class="lrn-review-form__star" aria-hidden="true">&#9733;</span>
					<span class="lrn-sr-only">
						<?php
						printf(
							/* translators: %d: star count, 1 through 5 */
							esc_html( _n( '%d star', '%d stars', $lrn_i, 'learnomy' ) ),
							(int) $lrn_i
						);
						?>
					</span>
				</label>
			<?php endfor; ?>
		</div>
	</fieldset>

	<div class="lrn-form-group">
		<label for="lrn-review-title" class="lrn-form-label">
			<?php esc_html_e( 'Title (optional)', 'learnomy' ); ?>
		</label>
		<input id="lrn-review-title"
			name="title"
			type="text"
			class="lrn-input"
			maxlength="<?php echo esc_attr( (string) \Learnomy\Models\Review::MAX_TITLE_LENGTH ); ?>"
			placeholder="<?php esc_attr_e( 'A short summary, e.g. Practical and well-paced.', 'learnomy' ); ?>">
	</div>

	<div class="lrn-form-group">
		<label for="lrn-review-content" class="lrn-form-label">
			<?php esc_html_e( 'Your review', 'learnomy' ); ?> <span aria-hidden="true">*</span>
		</label>
		<textarea id="lrn-review-content"
			name="content"
			class="lrn-input lrn-input--textarea"
			rows="4"
			required
			maxlength="<?php echo esc_attr( (string) \Learnomy\Models\Review::MAX_CONTENT_LENGTH ); ?>"
			placeholder="<?php esc_attr_e( 'What worked well for you, and who is this course best suited for?', 'learnomy' ); ?>"></textarea>
		<p class="lrn-form-hint" id="lrn-review-content-counter" role="status" aria-live="polite">
			<?php
			printf(
				/* translators: %s: number of characters still available. */
				esc_html__( '%s characters left', 'learnomy' ),
				esc_html( number_format_i18n( \Learnomy\Models\Review::MAX_CONTENT_LENGTH ) )
			);
			?>
		</p>
	</div>

	<button type="submit" class="lrn-btn lrn-btn--primary" id="lrn-review-form-submit">
		<?php esc_html_e( 'Post review', 'learnomy' ); ?>
	</button>
</form>
