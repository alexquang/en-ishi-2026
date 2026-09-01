<?php
/**
 * Mobile curriculum drawer.
 *
 * Wraps the existing `curriculum.php` partial in a slide-up bottom-sheet
 * on screens below 768px. Hidden at md+ where the inline sidebar takes over.
 *
 * Expects: same variables as `templates/partials/curriculum.php`
 *   $course        (object)
 *   $sections      (array)
 *   $lessons       (array)
 *   $current_id    (int|null)
 *   $progress_map  (array)
 *
 * Plus a per-page identifier:
 *   $drawer_id  (string)  Unique HTML id suffix (defaults to 'curriculum').
 *
 * Toggle is wired via the Interactivity API store `learnomy/curriculum`
 * which already exists for the desktop sidebar collapse — this partial
 * piggybacks on it for the mobile open/close.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$drawer_id = isset( $drawer_id ) && '' !== $drawer_id ? sanitize_html_class( (string) $drawer_id ) : 'curriculum';
$panel_id  = 'lrn-curriculum-drawer-' . $drawer_id;

// Local lrn-action variables for the toggle and close buttons.
$action_icon  = 'list';
$action_aria  = __( 'Open curriculum', 'learnomy' );
$action_label = __( 'Curriculum', 'learnomy' );
$action_title = null;
$action_href  = null;
$action_class = 'lrn-curriculum-drawer__toggle';
$action_attrs = [
	'aria-controls'               => $panel_id,
	'aria-expanded'               => 'false',
	'data-wp-bind--aria-expanded' => 'state.drawerOpen',
	'data-wp-on--click'           => 'actions.openDrawer',
	'data-wp-interactive'         => 'learnomy/curriculum',
];
require \Learnomy\template_path( 'partials/lrn-action.php' );
?>

<div
	class="lrn-curriculum-drawer"
	id="<?php echo esc_attr( $panel_id ); ?>"
	data-wp-interactive="learnomy/curriculum"
	data-wp-class--lrn-curriculum-drawer--open="state.drawerOpen"
	data-wp-bind--hidden="!state.drawerOpen"
	role="dialog"
	aria-modal="false"
	aria-label="<?php esc_attr_e( 'Course curriculum', 'learnomy' ); ?>"
	hidden
>
	<div
		class="lrn-curriculum-drawer__backdrop"
		data-wp-on--click="actions.closeDrawer"
		aria-hidden="true"
	></div>

	<div class="lrn-curriculum-drawer__sheet" role="document">
		<header class="lrn-curriculum-drawer__header">
			<h2 class="lrn-curriculum-drawer__title">
				<?php esc_html_e( 'Curriculum', 'learnomy' ); ?>
			</h2>
			<?php
			$action_icon  = 'x';
			$action_aria  = __( 'Close curriculum', 'learnomy' );
			$action_label = null;
			$action_class = 'lrn-curriculum-drawer__close';
			$action_attrs = [
				'data-wp-on--click'   => 'actions.closeDrawer',
				'data-wp-interactive' => 'learnomy/curriculum',
			];
			require \Learnomy\template_path( 'partials/lrn-action.php' );
			?>
		</header>

		<div class="lrn-curriculum-drawer__body">
			<?php
			// Re-include the canonical curriculum partial. It owns the
			// section/lesson list rendering — this drawer is purely the
			// mobile container. curriculum.php derives its list from
			// $course_id, so make sure it is set (the single-course page
			// includes this drawer before its own $course_id is in scope).
			if ( empty( $course_id ) && isset( $course ) && is_object( $course ) ) {
				$course_id = (int) $course->id;
			}
			$curriculum_partial = \Learnomy\template_path( 'partials/curriculum.php' );
			if ( file_exists( $curriculum_partial ) ) {
				include $curriculum_partial;
			}
			?>
		</div>
	</div>
</div>
