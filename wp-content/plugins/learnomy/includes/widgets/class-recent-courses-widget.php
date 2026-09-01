<?php
/**
 * Recent Courses Widget — shows recently published courses.
 *
 * @package Learnomy
 */

namespace Learnomy\Widgets;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;

class Recent_Courses_Widget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'learnomy_recent_courses',
			sprintf( /* translators: %s: plural Course label */ __( 'Recent %s', 'learnomy' ), \Learnomy\course_label( true ) ),
			[
				'description'                 => sprintf( /* translators: %s: plural Course label */ __( 'Displays the most recently published %s.', 'learnomy' ), \Learnomy\course_label( true ) ),
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

		$title = ! empty( $instance['title'] ) ? $instance['title'] : sprintf( /* translators: %s: plural Course label */ __( 'Recent %s', 'learnomy' ), \Learnomy\course_label( true ) );
		$count = isset( $instance['count'] ) ? absint( $instance['count'] ) : 5;
		$count = max( 1, min( 20, $count ) );

		$courses = Course::list_published( 1, $count );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['before_title'] . esc_html( apply_filters( 'widget_title', $title, $instance, $this->id_base ) ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( empty( $courses ) ) {
			echo '<p>' . esc_html( sprintf( /* translators: %s: plural Course label */ __( 'No %s found.', 'learnomy' ), \Learnomy\course_label( true ) ) ) . '</p>';
		} else {
			echo '<ul class="lrn-widget-list lrn-widget-list--recent">';
			foreach ( $courses as $course ) {
				$url   = \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) );
				$label = $course->title;
				$ts    = ! empty( $course->created_at ) ? strtotime( $course->created_at ) : 0;
				$date  = $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '';
				echo '<li class="lrn-widget-list__item">';
				echo '<a href="' . esc_url( $url ) . '" class="lrn-widget-list__link">' . esc_html( $label ) . '</a>';
				if ( $date ) {
					echo '<span class="lrn-widget-list__meta">' . esc_html( $date ) . '</span>';
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
		$title = ! empty( $instance['title'] ) ? $instance['title'] : sprintf( /* translators: %s: plural Course label */ __( 'Recent %s', 'learnomy' ), \Learnomy\course_label( true ) );
		$count = isset( $instance['count'] ) ? absint( $instance['count'] ) : 5;
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
			<label for="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>">
				<?php esc_html_e( 'Number of courses to show:', 'learnomy' ); ?>
			</label>
			<input
				class="tiny-text"
				id="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'count' ) ); ?>"
				type="number"
				min="1"
				max="20"
				value="<?php echo esc_attr( $count ); ?>">
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
			'title' => sanitize_text_field( $new_instance['title'] ?? '' ),
			'count' => max( 1, min( 20, absint( $new_instance['count'] ?? 5 ) ) ),
		];
	}
}
