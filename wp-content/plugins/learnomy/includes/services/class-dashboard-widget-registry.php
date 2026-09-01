<?php
/**
 * Dashboard Widget Registry — single extension point for both student and
 * instructor dashboards.
 *
 * Modules (Free first-party AND Pro extensions) register widgets via a
 * filter; the template loops the sorted list and lets the shell partial
 * render each one. Nothing is hardcoded in the dashboard templates.
 *
 * Filter contract:
 *
 *     add_filter( 'learnomy_dashboard_widgets',
 *         function ( array $widgets, string $surface, array $ctx ): array {
 *             if ( 'student' !== $surface ) { return $widgets; }
 *             $widgets[] = [
 *                 'id'           => 'my-spaces',
 *                 'surface'      => 'student',
 *                 'priority'     => 25,
 *                 'title'        => __( 'My Learning Spaces', 'learnomy' ),
 *                 'shell'        => 'card',          // card | naked | flush | hero | none
 *                 'is_available' => fn ( $c ) => ! empty( $c['view_data']['my_spaces'] ),
 *                 'render'       => function ( $c ) { ob_start(); include …; return ob_get_clean(); },
 *             ];
 *             return $widgets;
 *         },
 *         10,
 *         3
 *     );
 *
 * Pass A of plan/2026-05-28-dashboard-widgets.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

class Dashboard_Widget_Registry {

	/**
	 * Collect, validate, filter, and sort the widget list for a surface.
	 *
	 * @param string      $surface 'student' or 'instructor'.
	 * @param array       $context user_id, user, view_data, surface, route.
	 * @param string|null $section Optional section filter. When set, only widgets
	 *                             whose `section` matches are returned (a widget
	 *                             with no `section` is treated as 'overview').
	 *                             Lets the instructor area split one long
	 *                             dashboard into rail sections without forking
	 *                             the widget registry.
	 * @return array Sorted, available, validated widget definitions.
	 */
	public static function for_surface( string $surface, array $context, ?string $section = null ): array {
		// Modules contribute via this single filter — both Free and Pro use
		// the same surface. There is no privileged in-template branch.
		$raw = apply_filters( 'learnomy_dashboard_widgets', array(), $surface, $context );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$seen  = array();
		$valid = array();
		foreach ( $raw as $widget ) {
			if ( ! self::is_well_formed( $widget ) ) {
				continue;
			}
			// `surface` may be the literal target OR 'both'. Drop mismatches.
			if ( 'both' !== $widget['surface'] && $surface !== $widget['surface'] ) {
				continue;
			}
			// Section filter — a widget with no declared section belongs to the
			// 'overview' (the dashboard home). Only applied when a section is
			// requested, so existing callers (no section) are unaffected.
			if ( null !== $section && ( (string) ( $widget['section'] ?? 'overview' ) ) !== $section ) {
				continue;
			}
			// Dedupe by id; first-registered wins so a module can override
			// a previously-registered widget by registering with the same id
			// at an earlier priority — and a careless duplicate is silenced.
			$id = (string) $widget['id'];
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			// Honor is_available — a widget that has nothing to show is
			// silently dropped, so feature-off paths produce zero empty
			// states.
			$available_cb = $widget['is_available'] ?? null;
			if ( is_callable( $available_cb ) && ! call_user_func( $available_cb, $context ) ) {
				continue;
			}

			$valid[] = $widget;
		}

		// Ascending priority — first widget rendered is the smallest number.
		usort(
			$valid,
			static function ( $a, $b ) {
				return ( (int) ( $a['priority'] ?? 10 ) ) <=> ( (int) ( $b['priority'] ?? 10 ) );
			}
		);

		return $valid;
	}

	/**
	 * Render a widget through the shell partial. Used by the dashboard
	 * templates to centralize the card / naked / flush / hero / none
	 * decision in one place — no per-section markup drift.
	 *
	 * @param array $widget Validated widget definition.
	 * @param array $context Same context passed to for_surface().
	 */
	public static function render( array $widget, array $context ): void {
		// Expose the widget + context as locals to the shell partial. Both
		// names are stable contract for any custom widget renderer.
		$lrn_widget  = $widget;
		$lrn_context = $context;
		require \Learnomy\template_path( 'partials/dashboard-widget-shell.php' );
	}

	/**
	 * Minimum keys we require to render anything safely. Missing the render
	 * callable means we'd output nothing useful, so the widget is dropped.
	 */
	private static function is_well_formed( $widget ): bool {
		if ( ! is_array( $widget ) ) {
			return false;
		}
		if ( empty( $widget['id'] ) || ! is_string( $widget['id'] ) ) {
			return false;
		}
		if ( empty( $widget['surface'] ) || ! is_string( $widget['surface'] ) ) {
			return false;
		}
		if ( ! isset( $widget['render'] ) || ! is_callable( $widget['render'] ) ) {
			return false;
		}
		return true;
	}
}
