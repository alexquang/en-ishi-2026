<?php
/**
 * Course Progress Widget — shows the logged-in user's in-progress courses.
 *
 * @package Learnomy
 */

namespace Learnomy\Widgets;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Services\Resume;

class Course_Progress_Widget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'learnomy_course_progress',
			__( 'My Course Progress', 'learnomy' ),
			[
				'description'                 => __( "Shows the logged-in student's course progress.", 'learnomy' ),
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

		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'My Progress', 'learnomy' );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['before_title'] . esc_html( apply_filters( 'widget_title', $title, $instance, $this->id_base ) ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( get_permalink() );
			echo '<p><a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Log in to see your progress', 'learnomy' ) . '</a></p>';
			echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$output = ob_get_clean();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered widget output.
			echo apply_filters( 'learnomy_widget_output', $output, $args, $instance, $this->id_base );
			return;
		}

		$user_id = get_current_user_id();
		// Distinct so a course held via two sources (free + membership/Space) is
		// listed once, not twice (get_for_user returns one row per source).
		$enrollments = Enrollment::get_distinct_for_user( $user_id );
		// Liveness ('active') AND the shared in-progress predicate: this widget
		// holds raw rows, so it asks the status question itself — is_in_progress()
		// deliberately answers only "enrolled and not finished" (Basecamp 10183993951).
		$active = array_filter( $enrollments, fn( $e ) => 'active' === $e->status && Enrollment::is_in_progress( $e ) );

		// Each row deep-links to the resume lesson (Resume::url), a bounded read
		// per row, so cap this sidebar list on large accounts. Filterable.
		$widget_max = (int) apply_filters( 'learnomy_progress_widget_max', 10 );
		if ( $widget_max > 0 && count( $active ) > $widget_max ) {
			$active = array_slice( $active, 0, $widget_max );
		}

		if ( empty( $active ) ) {
			echo '<p>' . esc_html__( 'No courses in progress', 'learnomy' ) . '</p>';
			echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$output = ob_get_clean();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered widget output.
			echo apply_filters( 'learnomy_widget_output', $output, $args, $instance, $this->id_base );
			return;
		}

		// Batch-fetch the course rows in one query (was Course::find() per row = N+1).
		$course_ids = array_map( static fn( $e ) => (int) $e->course_id, $active );
		$course_map = ! empty( $course_ids ) ? Course::find_in( $course_ids ) : array();

		echo '<ul class="lrn-widget-list lrn-widget-progress">';
		foreach ( $active as $enrollment ) {
			$course = $course_map[ (int) $enrollment->course_id ] ?? null;

			if ( ! $course ) {
				continue;
			}

			$pct = absint( $enrollment->progress_pct ?? 0 );
			// Deep-link to the resume lesson, not the course landing page.
			$url  = Resume::url( $user_id, $course );
			$name = $course->title;

			echo '<li class="lrn-widget-list__item lrn-widget-progress__item">';
			echo '<a href="' . esc_url( $url ) . '" class="lrn-widget-list__link">' . esc_html( $name ) . '</a>';
			echo '<div class="lrn-progress-bar lrn-progress-bar--xs" role="progressbar" aria-valuenow="' . esc_attr( $pct ) . '" aria-valuemin="0" aria-valuemax="100">';
			echo '<div class="lrn-progress-bar__fill" style="width:' . esc_attr( $pct ) . '%"></div>';
			echo '</div>';
			echo '<span class="lrn-widget-progress__pct">' . esc_html( $pct . '%' ) . '</span>';
			echo '</li>';
		}
		echo '</ul>';

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$output = ob_get_clean();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered widget output.
		echo apply_filters( 'learnomy_widget_output', $output, $args, $instance, $this->id_base );
	}

	/**
	 * @param array $instance Saved widget settings.
	 */
	public function form( $instance ): void {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'My Progress', 'learnomy' );
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
		];
	}
}
