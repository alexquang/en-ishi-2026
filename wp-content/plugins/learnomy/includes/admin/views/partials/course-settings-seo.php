<?php
/**
 * Search & sharing section — per-course SEO overrides.
 *
 * Every field is optional. Left empty, the course keeps deriving its snippet
 * exactly as it did before these columns existed, so this section changes
 * nothing for a site owner who ignores it.
 *
 * Worth knowing while reading this: courses live in a custom table, so Yoast and
 * RankMath cannot see them, and the SEO emitter deliberately does not yield to
 * those plugins on our own routes. This section is therefore the only place a
 * course's search snippet can be controlled — not one option among several.
 *
 * @var object|null $course
 * @var bool        $is_edit
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_seo_title    = $is_edit ? (string) ( $course->seo_title ?? '' ) : '';
$lrn_seo_desc     = $is_edit ? (string) ( $course->meta_description ?? '' ) : '';
$lrn_og_image_url = $is_edit ? (string) ( $course->og_image_url ?? '' ) : '';
$lrn_og_image_id  = $is_edit ? (int) ( $course->og_image_id ?? 0 ) : 0;
$lrn_meta_robots  = $is_edit ? (string) ( $course->meta_robots ?? '' ) : '';

// Only the directives the read path accepts. Free text is deliberately not
// offered: a typo like "no-index" is ignored by crawlers, so the owner would
// believe a course was hidden while it stayed indexed.
$lrn_robots_choices = array(
	''                 => __( 'Visible in search (default)', 'learnomy' ),
	'noindex'          => __( 'Hide from search', 'learnomy' ),
	'noindex,nofollow' => __( 'Hide from search and do not follow its links', 'learnomy' ),
);
?>
<section class="lrn-settings-section" data-section="seo">
	<button type="button" class="lrn-settings-section__header" aria-expanded="false">
		<span class="lrn-settings-section__heading">
			<i data-lucide="search"></i>
			<span><?php esc_html_e( 'Search & Sharing', 'learnomy' ); ?></span>
		</span>
		<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
	</button>
	<div class="lrn-settings-section__body">
		<p class="lrn-settings-section__help">
			<?php esc_html_e( 'Control how this course appears in search results and when its link is shared. Leave a field empty to build it from the course itself.', 'learnomy' ); ?>
		</p>

		<div class="lrn-form-field">
			<label for="lrn-course-seo-title"><?php esc_html_e( 'Search title', 'learnomy' ); ?></label>
			<input
				type="text"
				id="lrn-course-seo-title"
				name="seo_title"
				maxlength="255"
				value="<?php echo esc_attr( $lrn_seo_title ); ?>"
				placeholder="<?php echo esc_attr( $is_edit ? (string) ( $course->title ?? '' ) : '' ); ?>"
			>
			<p class="description"><?php esc_html_e( 'Shown as the page title in search results. Defaults to the course title.', 'learnomy' ); ?></p>
		</div>

		<div class="lrn-form-field">
			<label for="lrn-course-meta-description"><?php esc_html_e( 'Search description', 'learnomy' ); ?></label>
			<textarea
				id="lrn-course-meta-description"
				name="meta_description"
				rows="3"
				maxlength="320"
			><?php echo esc_textarea( $lrn_seo_desc ); ?></textarea>
			<p class="description">
				<?php esc_html_e( 'Around 155 characters is what most search engines show. Longer text is kept exactly as written and may be shortened by the search engine itself.', 'learnomy' ); ?>
			</p>
		</div>

		<div class="lrn-form-grid lrn-form-grid--2">
			<div class="lrn-form-field">
				<label for="lrn-course-og-image-url"><?php esc_html_e( 'Share image URL', 'learnomy' ); ?></label>
				<input
					type="url"
					id="lrn-course-og-image-url"
					name="og_image_url"
					maxlength="500"
					value="<?php echo esc_url( $lrn_og_image_url ); ?>"
					placeholder="https://"
				>
				<p class="description"><?php esc_html_e( 'Used when the link is shared. Defaults to the course thumbnail.', 'learnomy' ); ?></p>
			</div>
			<div class="lrn-form-field">
				<label for="lrn-course-og-image-id"><?php esc_html_e( 'Share image (media ID)', 'learnomy' ); ?></label>
				<input
					type="number"
					id="lrn-course-og-image-id"
					name="og_image_id"
					min="0"
					step="1"
					value="<?php echo $lrn_og_image_id > 0 ? esc_attr( (string) $lrn_og_image_id ) : ''; ?>"
				>
				<p class="description"><?php esc_html_e( 'Used only when no URL is set above.', 'learnomy' ); ?></p>
			</div>
		</div>

		<div class="lrn-form-field">
			<label for="lrn-course-meta-robots"><?php esc_html_e( 'Search visibility', 'learnomy' ); ?></label>
			<select id="lrn-course-meta-robots" name="meta_robots">
				<?php foreach ( $lrn_robots_choices as $lrn_val => $lrn_label ) : ?>
					<option value="<?php echo esc_attr( $lrn_val ); ?>" <?php selected( $lrn_meta_robots, $lrn_val ); ?>>
						<?php echo esc_html( $lrn_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description">
				<?php esc_html_e( 'Hiding a course from search does not restrict who can open it. Use the visibility setting for that.', 'learnomy' ); ?>
			</p>
		</div>
	</div>
</section>
