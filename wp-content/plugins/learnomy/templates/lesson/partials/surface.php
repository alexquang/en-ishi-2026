<?php
/**
 * Lesson — embedded layout content surface (buffered hook wrapper).
 *
 * Fires a single placement `do_action` and emits its wrapping `<div>` ONLY
 * when a listener actually produced output. This keeps unhooked surfaces fully
 * invisible (no empty bordered box leaks through, which a CSS `:empty` rule
 * can't guarantee once the hook emits stray whitespace) while a hooked surface
 * renders as an intentional, styled slot. Placement only — NOT an ad system.
 *
 * Included via `include` from templates/lesson/embedded.php, inheriting:
 *   $lesson              (object) current lesson row
 *   $lrn_surface_hook    (string) the do_action hook name
 *   $lrn_surface_class   (string) CSS class for the wrapper div
 *   $lrn_surface_hidden  (bool)   optional — render the wrapper with [hidden]
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

ob_start();
do_action( $lrn_surface_hook, $lesson );
$lrn_surface_html = trim( (string) ob_get_clean() );

if ( '' !== $lrn_surface_html ) :
	$lrn_surface_attr = ! empty( $lrn_surface_hidden ) ? ' hidden' : '';
	?>
	<div class="<?php echo esc_attr( $lrn_surface_class ); ?>"<?php echo $lrn_surface_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal. ?>>
		<?php echo $lrn_surface_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- listener owns escaping per the hook contract (HOOKS.md). ?>
	</div>
	<?php
endif;
