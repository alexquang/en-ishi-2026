<?php
/**
 * Prerequisites, Difficulty & Target section — Phase 1 course settings.
 *
 * @package Learnomy
 *
 * @var array        $course_settings
 * @var object|null  $course
 * @var bool         $is_edit
 * @var object[]     $all_courses      Every other course for the multi-select.
 * @var int[]        $current_prereqs  Currently selected prerequisite course IDs.
 */

defined( 'ABSPATH' ) || exit;

$current_difficulty = $is_edit ? ( $course->difficulty ?? '' ) : '';
$duration_total_s   = $is_edit ? (int) ( $course->duration_seconds ?? 0 ) : 0;
$duration_hours     = (int) floor( $duration_total_s / 3600 );
$duration_minutes   = (int) floor( ( $duration_total_s % 3600 ) / 60 );

$language        = (string) ( $course_settings['language'] ?? '' );
$target_audience = (string) ( $course_settings['target_audience'] ?? '' );
$learning_raw    = $course_settings['learning_outcomes'] ?? array();
$learning_lines  = is_array( $learning_raw ) ? implode( "\n", array_map( 'strval', $learning_raw ) ) : (string) $learning_raw;

// Site locales + English fallback so the select is always populated.
$available_locales = array( 'en_US' => 'English (United States)' );
if ( function_exists( 'get_available_languages' ) ) {
	$installed = get_available_languages();
	if ( function_exists( 'format_code_lang' ) ) {
		foreach ( $installed as $code ) {
			$label                      = format_code_lang( $code );
			$available_locales[ $code ] = $label ? $label : $code;
		}
	} else {
		foreach ( $installed as $code ) {
			$available_locales[ $code ] = $code;
		}
	}
}
ksort( $available_locales );

$difficulties = array(
	''             => __( 'Select', 'learnomy' ),
	'beginner'     => __( 'Beginner', 'learnomy' ),
	'intermediate' => __( 'Intermediate', 'learnomy' ),
	'advanced'     => __( 'Advanced', 'learnomy' ),
	'expert'       => __( 'Expert', 'learnomy' ),
);
?>
<section class="lrn-settings-section is-open" data-section="target">
	<button type="button" class="lrn-settings-section__header" aria-expanded="true">
		<span class="lrn-settings-section__heading">
			<i data-lucide="target"></i>
			<span><?php esc_html_e( 'Prerequisites, Difficulty & Target', 'learnomy' ); ?></span>
		</span>
		<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
	</button>
	<div class="lrn-settings-section__body">
		<p class="lrn-settings-section__help">
			<?php esc_html_e( 'Describe who this course is for, what they should know going in, and what they will leave with.', 'learnomy' ); ?>
		</p>

		<div class="lrn-form-grid lrn-form-grid--2">
			<div class="lrn-form-field">
				<label for="lrn-course-difficulty"><?php esc_html_e( 'Difficulty', 'learnomy' ); ?></label>
				<select id="lrn-course-difficulty" name="difficulty">
					<?php foreach ( $difficulties as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_difficulty, $val ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="lrn-form-field">
				<label for="lrn-course-language"><?php esc_html_e( 'Language', 'learnomy' ); ?></label>
				<select id="lrn-course-language" name="language">
					<option value=""><?php esc_html_e( 'Site default', 'learnomy' ); ?></option>
					<?php foreach ( $available_locales as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $language, $code ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<div class="lrn-form-field">
			<label for="lrn-course-duration-hours"><?php esc_html_e( 'Estimated Duration', 'learnomy' ); ?></label>
			<div class="lrn-duration-row">
				<span class="lrn-duration-row__unit">
					<input type="number" id="lrn-course-duration-hours" name="duration_hours"
						min="0" step="1"
						value="<?php echo esc_attr( (string) $duration_hours ); ?>"
						class="small-text">
					<label for="lrn-course-duration-hours"><?php esc_html_e( 'hours', 'learnomy' ); ?></label>
				</span>
				<span class="lrn-duration-row__unit">
					<input type="number" id="lrn-course-duration-minutes" name="duration_minutes"
						min="0" max="59" step="1"
						value="<?php echo esc_attr( (string) $duration_minutes ); ?>"
						class="small-text">
					<label for="lrn-course-duration-minutes"><?php esc_html_e( 'minutes', 'learnomy' ); ?></label>
				</span>
			</div>
			<p class="lrn-form-help"><?php esc_html_e( 'Total estimated time to complete. Shown on the sales page.', 'learnomy' ); ?></p>
		</div>

		<div class="lrn-form-field lrn-form-field--wide">
			<label for="lrn-course-target-audience"><?php esc_html_e( 'Target Audience', 'learnomy' ); ?></label>
			<textarea id="lrn-course-target-audience" name="target_audience"
				rows="3" class="large-text"
				placeholder="<?php esc_attr_e( 'e.g. Intermediate developers looking to master React hooks.', 'learnomy' ); ?>"><?php echo esc_textarea( $target_audience ); ?></textarea>
		</div>

		<div class="lrn-form-field lrn-form-field--wide">
			<label for="lrn-course-learning-outcomes"><?php esc_html_e( 'What you will learn', 'learnomy' ); ?></label>
			<textarea id="lrn-course-learning-outcomes" name="learning_outcomes"
				rows="5" class="large-text"
				placeholder="<?php esc_attr_e( "One outcome per line.\ne.g.\nBuild reusable hooks\nManage state with Context\nDebug re-renders", 'learnomy' ); ?>"><?php echo esc_textarea( $learning_lines ); ?></textarea>
			<p class="lrn-form-help"><?php esc_html_e( 'Shown as bullet points on the sales page. One line = one bullet.', 'learnomy' ); ?></p>
		</div>

		<?php if ( $is_edit && ! empty( $all_courses ) ) : ?>
			<div class="lrn-form-field lrn-form-field--wide">
				<label for="lrn-course-prereq-search"><?php esc_html_e( 'Prerequisites', 'learnomy' ); ?></label>
				<p class="lrn-form-help"><?php esc_html_e( 'Students must complete these courses before enrolling.', 'learnomy' ); ?></p>
				<div class="lrn-searchable-select" data-lrn-searchable-select>
					<input type="search" id="lrn-course-prereq-search"
						class="lrn-searchable-select__search regular-text"
						placeholder="<?php esc_attr_e( 'Search courses…', 'learnomy' ); ?>"
						data-lrn-search-input>
					<div class="lrn-searchable-select__list" data-lrn-search-list>
						<?php
						foreach ( $all_courses as $c ) :
							if ( $is_edit && (int) $c->id === (int) $course->id ) {
								continue;
							}
							?>
							<label data-lrn-search-item>
								<input type="checkbox" name="prerequisites[]" value="<?php echo absint( $c->id ); ?>"
									<?php checked( in_array( (int) $c->id, $current_prereqs, true ) ); ?>>
								<?php echo esc_html( $c->title ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>
</section>
