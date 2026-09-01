<?php
/**
 * Course Categories Widget — lists course categories with optional counts.
 *
 * @package Learnomy
 */

namespace Learnomy\Widgets;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Category;

class Course_Categories_Widget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'learnomy_course_categories',
			sprintf( /* translators: 1: singular Course label, 2: plural Category label */ __( '%1$s %2$s', 'learnomy' ), \Learnomy\course_label(), \Learnomy\category_label( true ) ),
			[
				'description'                 => sprintf( /* translators: 1: singular Course label, 2: plural Category label */ __( 'Displays a list of %1$s %2$s, each with an optional count.', 'learnomy' ), \Learnomy\course_label(), \Learnomy\category_label( true ) ),
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

		$title      = ! empty( $instance['title'] ) ? $instance['title'] : sprintf( /* translators: 1: singular Course label, 2: plural Category label */ __( '%1$s %2$s', 'learnomy' ), \Learnomy\course_label(), \Learnomy\category_label( true ) );
		$show_count = ! empty( $instance['show_count'] );

		$categories = Category::list_top_level();

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['before_title'] . esc_html( apply_filters( 'widget_title', $title, $instance, $this->id_base ) ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( empty( $categories ) ) {
			echo '<p>' . esc_html( sprintf( /* translators: %s: plural Category label */ __( 'No %s found.', 'learnomy' ), \Learnomy\category_label( true ) ) ) . '</p>';
		} else {
			echo '<ul class="lrn-widget-list lrn-widget-list--categories">';
			foreach ( $categories as $cat ) {
				$url   = add_query_arg( 'category', $cat->slug, \Learnomy\route_url( 'catalog' ) );
				$label = $cat->name;
				echo '<li class="lrn-widget-list__item">';
				echo '<a href="' . esc_url( $url ) . '" class="lrn-widget-list__link">' . esc_html( $label ) . '</a>';
				if ( $show_count ) {
					$count_label = sprintf(
						/* translators: %d: number of courses */
						_n( '(%d)', '(%d)', absint( $cat->course_count ), 'learnomy' ),
						absint( $cat->course_count )
					);
					echo ' <span class="lrn-widget-list__count">' . esc_html( $count_label ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$output = ob_get_clean();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered widget output.
		echo apply_filters( 'learnomy_widget_output', $output, $args, $instance, $this->id_base );
	}

	/**
	 * @param array $instance Saved widget settings.
	 */
	public function form( $instance ): void {
		$title      = ! empty( $instance['title'] ) ? $instance['title'] : sprintf( /* translators: 1: singular Course label, 2: plural Category label */ __( '%1$s %2$s', 'learnomy' ), \Learnomy\course_label(), \Learnomy\category_label( true ) );
		$show_count = ! empty( $instance['show_count'] );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'learnomy' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<input
				type="checkbox"
				id="<?php echo esc_attr( $this->get_field_id( 'show_count' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_count' ) ); ?>"
				<?php checked( $show_count ); ?>
				value="1">
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_count' ) ); ?>">
				<?php esc_html_e( 'Show course count', 'learnomy' ); ?>
			</label>
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
			'title'      => sanitize_text_field( $new_instance['title'] ?? '' ),
			'show_count' => ! empty( $new_instance['show_count'] ) ? 1 : 0,
		];
	}
}
