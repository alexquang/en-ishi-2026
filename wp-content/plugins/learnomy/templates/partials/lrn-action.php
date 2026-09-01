<?php
/**
 * Icon-action partial.
 *
 * Canonical icon-button (or icon-link). Every interactive icon-driven
 * affordance in the frontend templates routes through this partial so we
 * keep one accessible, focusable, 44px-touch-target component.
 *
 * Expects:
 *   $action_icon  (string)        Lucide icon name. Required.
 *   $action_aria  (string)        Screen-reader label. Required.
 *
 * Optional:
 *   $action_label (string|null)   Visible one-word label. Omit for icon-only.
 *   $action_title (string|null)   Tooltip text on hover.
 *   $action_href  (string|null)   Anchor href. When set, renders <a>; else <button>.
 *   $action_type  (string)        Button type when not a link. Default 'button'.
 *   $action_class (string)        Extra class names appended after the base class.
 *   $action_attrs (array)         Map of attribute => value rendered onto the element.
 *                                 Use for data-* hooks, aria-*, name, value, etc.
 *   $action_disabled (bool)       Adds the disabled attribute (button only).
 *   $action_variant (string|null) Optional variant: 'primary' | 'danger' | 'ghost'.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$action_icon     = isset( $action_icon ) ? (string) $action_icon : '';
$action_aria     = isset( $action_aria ) ? (string) $action_aria : '';
$action_label    = isset( $action_label ) && '' !== $action_label ? (string) $action_label : null;
$action_title    = isset( $action_title ) && '' !== $action_title ? (string) $action_title : null;
$action_href     = isset( $action_href ) && '' !== $action_href ? (string) $action_href : null;
$action_type     = isset( $action_type ) ? (string) $action_type : 'button';
$action_class    = isset( $action_class ) ? (string) $action_class : '';
$action_attrs    = isset( $action_attrs ) && is_array( $action_attrs ) ? $action_attrs : [];
$action_disabled = ! empty( $action_disabled );
$action_variant  = isset( $action_variant ) && '' !== $action_variant ? (string) $action_variant : null;

if ( '' === $action_icon || '' === $action_aria ) {
	return;
}

$classes = [ 'lrn-action' ];
if ( null !== $action_label ) {
	$classes[] = 'lrn-action--labeled';
}
if ( null !== $action_variant ) {
	$classes[] = 'lrn-action--' . preg_replace( '/[^a-z0-9_-]/', '', strtolower( $action_variant ) );
}
if ( '' !== $action_class ) {
	$classes[] = $action_class;
}
$class_attr = implode( ' ', array_filter( $classes ) );

$is_link = null !== $action_href;
$tag     = $is_link ? 'a' : 'button';
?>
<<?php echo esc_attr( $tag ); ?>
	class="<?php echo esc_attr( $class_attr ); ?>"
	<?php if ( $is_link ) : ?>
		href="<?php echo esc_url( $action_href ); ?>"
	<?php else : ?>
		type="<?php echo esc_attr( $action_type ); ?>"
		<?php
		if ( $action_disabled ) {
			echo 'disabled';
		}
		?>
	<?php endif; ?>
	aria-label="<?php echo esc_attr( $action_aria ); ?>"
	<?php if ( null !== $action_title ) : ?>
		title="<?php echo esc_attr( $action_title ); ?>"
	<?php endif; ?>
	<?php
	foreach ( $action_attrs as $attr_name => $attr_value ) {
		if ( ! is_string( $attr_name ) || '' === $attr_name ) {
			continue;
		}
		// Allow boolean attributes — print just the name when value is true.
		if ( true === $attr_value ) {
			echo ' ' . esc_attr( $attr_name );
			continue;
		}
		if ( false === $attr_value || null === $attr_value ) {
			continue;
		}
		echo ' ' . esc_attr( $attr_name ) . '="' . esc_attr( (string) $attr_value ) . '"';
	}
	?>
>
	<i data-lucide="<?php echo esc_attr( $action_icon ); ?>" aria-hidden="true"></i>
	<?php if ( null !== $action_label ) : ?>
		<span class="lrn-action__label"><?php echo esc_html( $action_label ); ?></span>
	<?php endif; ?>
</<?php echo esc_attr( $tag ); ?>>
