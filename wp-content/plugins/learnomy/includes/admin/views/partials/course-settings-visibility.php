<?php
/**
 * Visibility & Media section — Phase 1 course settings.
 *
 * Most media controls (thumbnail, video URL) already live elsewhere in
 * the form (sidebar card, hero video). This section only surfaces the
 * `is_featured` flag that lives in settings_json. Intro video URL is
 * exposed here too so instructors can tune both catalog flags together.
 *
 * @package Learnomy
 *
 * @var array        $course_settings
 * @var object|null  $course
 * @var bool         $is_edit
 */

defined( 'ABSPATH' ) || exit;

$is_featured = (bool) ( $course_settings['is_featured'] ?? false );
$video_url   = $is_edit ? ( $course->video_url ?? '' ) : '';
?>
<section class="lrn-settings-section is-open" data-section="visibility">
	<button type="button" class="lrn-settings-section__header" aria-expanded="true">
		<span class="lrn-settings-section__heading">
			<i data-lucide="eye"></i>
			<span><?php esc_html_e( 'Visibility & Media', 'learnomy' ); ?></span>
		</span>
		<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
	</button>
	<div class="lrn-settings-section__body">
		<p class="lrn-settings-section__help">
			<?php esc_html_e( 'Tune how the course appears in the catalog and which intro media plays on the sales page.', 'learnomy' ); ?>
		</p>

		<div class="lrn-form-field">
			<label class="lrn-checkbox-label" for="lrn-course-featured">
				<input type="checkbox" id="lrn-course-featured"
					name="is_featured" value="1"
					<?php checked( $is_featured ); ?>>
				<?php esc_html_e( 'Featured in catalog', 'learnomy' ); ?>
			</label>
			<p class="lrn-form-help"><?php esc_html_e( 'Shows on the homepage featured row and in any "featured" block instance.', 'learnomy' ); ?></p>
		</div>

		<div class="lrn-form-field lrn-form-field--wide">
			<label for="lrn-course-video"><?php esc_html_e( 'Intro Video URL', 'learnomy' ); ?></label>
			<input type="url" id="lrn-course-video" name="video_url" class="regular-text"
				value="<?php echo esc_url( (string) $video_url ); ?>"
				placeholder="https://youtube.com/watch?v=…">
			<p class="lrn-form-help"><?php esc_html_e( 'YouTube, Vimeo, or a direct MP4 URL. Plays on the sales page above the fold.', 'learnomy' ); ?></p>
		</div>
	</div>
</section>
