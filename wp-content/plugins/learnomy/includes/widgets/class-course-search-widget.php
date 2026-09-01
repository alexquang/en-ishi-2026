<?php
/**
 * Course Search Widget — a search form scoped to courses.
 *
 * @package Learnomy
 */

namespace Learnomy\Widgets;

defined( 'ABSPATH' ) || exit;

class Course_Search_Widget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'learnomy_course_search',
			sprintf( /* translators: %s: singular Course label */ __( '%s Search', 'learnomy' ), \Learnomy\course_label() ),
			[
				'description'                 => sprintf( /* translators: %s: plural Course label */ __( 'A search form for finding %s.', 'learnomy' ), \Learnomy\course_label( true ) ),
				'customize_selective_refresh' => true,
			]
		);
	}

	/**
	 * @param array $args     Widget display arguments.
	 * @param array $instance Saved widget settings.
	 */
	public function widget( $args, $instance ): void {
		ob_start();

		// Sidebar-sized bundle: the base stylesheet plus the embedded-component
		// sheet this widget's markup is written against. No JavaScript — a
		// widget that draws a list of links has no store to hydrate, and must
		// not drag a route's player/vendor bundles onto a theme page.
		\Learnomy\Template_Loader::enqueue_for_surface( 'widget' );

		$title       = ! empty( $instance['title'] ) ? $instance['title'] : '';
		$placeholder = ! empty( $instance['placeholder'] ) ? $instance['placeholder'] : sprintf( /* translators: %s: plural Course label */ __( 'Search %s…', 'learnomy' ), \Learnomy\course_label( true ) );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $title ) {
			echo $args['before_title'] . esc_html( apply_filters( 'widget_title', $title, $instance, $this->id_base ) ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$search_url = \Learnomy\route_url( 'catalog' );
		$query_val  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<form
			role="search"
			method="get"
			class="lrn-course-search"
			action="<?php echo esc_url( $search_url ); ?>">
			<label class="screen-reader-text" for="<?php echo esc_attr( $this->id . '-search-field' ); ?>">
				<?php esc_html_e( 'Search courses', 'learnomy' ); ?>
			</label>
			<div class="lrn-course-search__inner">
				<input
					type="search"
					id="<?php echo esc_attr( $this->id . '-search-field' ); ?>"
					class="lrn-course-search__input"
					name="s"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					value="<?php echo esc_attr( $query_val ); ?>">
				<button type="submit" class="lrn-btn lrn-btn--primary lrn-course-search__btn" aria-label="<?php esc_attr_e( 'Search', 'learnomy' ); ?>">
					<?php esc_html_e( 'Search', 'learnomy' ); ?>
				</button>
			</div>
		</form>
		<?php

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$output = ob_get_clean();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered widget output.
		echo apply_filters( 'learnomy_widget_output', $output, $args, $instance, $this->id_base );
	}

	/**
	 * @param array $instance Saved widget settings.
	 */
	public function form( $instance ): void {
		$title       = ! empty( $instance['title'] ) ? $instance['title'] : '';
		$placeholder = ! empty( $instance['placeholder'] ) ? $instance['placeholder'] : sprintf( /* translators: %s: plural Course label */ __( 'Search %s…', 'learnomy' ), \Learnomy\course_label( true ) );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title (optional):', 'learnomy' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'placeholder' ) ); ?>">
				<?php esc_html_e( 'Placeholder text:', 'learnomy' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'placeholder' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'placeholder' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $placeholder ); ?>">
		</p>
		<?php
	}

	/**
	 * @param array $new_instance New widget settings.
	 * @param array $old_instance Previous widget settings.
	 * @return array Sanitized settings.
	 */
	public function update( $new_instance, $old_instance ): array {
		return [
			'title'       => sanitize_text_field( $new_instance['title'] ?? '' ),
			'placeholder' => sanitize_text_field( $new_instance['placeholder'] ?? '' ),
		];
	}
}
