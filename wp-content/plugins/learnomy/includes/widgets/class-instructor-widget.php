<?php
/**
 * Instructor Widget — shows a featured instructor profile card.
 *
 * @package Learnomy
 */

namespace Learnomy\Widgets;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;

class Instructor_Widget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'learnomy_instructor',
			__( 'Featured Instructor', 'learnomy' ),
			[
				'description'                 => __( 'Displays a featured instructor card with avatar, bio, and course count.', 'learnomy' ),
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

		$title   = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Featured Instructor', 'learnomy' );
		$user_id = isset( $instance['user_id'] ) ? absint( $instance['user_id'] ) : 0;

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['before_title'] . esc_html( apply_filters( 'widget_title', $title, $instance, $this->id_base ) ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! $user_id ) {
			echo '<p>' . esc_html__( 'No instructor selected.', 'learnomy' ) . '</p>';
			echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$output = ob_get_clean();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered widget output.
			echo apply_filters( 'learnomy_widget_output', $output, $args, $instance, $this->id_base );
			return;
		}

		// The form takes a raw numeric user id, so ANY member id rendered a
		// "Featured Instructor" card with that person's name, avatar and bio — a
		// mistyped digit published a student. Gate on the one predicate every
		// instructor surface uses; viewer 0 so the widget shows the site owner
		// exactly what a visitor sees. The two failure branches are deliberately
		// merged into one message: "no such user" and "not an instructor" must
		// not be distinguishable here either.
		$user = get_userdata( $user_id );
		if ( ! $user || ! \Learnomy\Models\Instructor::has_public_profile( $user_id, 0 ) ) {
			echo '<p>' . esc_html__( 'Instructor not found.', 'learnomy' ) . '</p>';
			echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$output = ob_get_clean();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered widget output.
			echo apply_filters( 'learnomy_widget_output', $output, $args, $instance, $this->id_base );
			return;
		}

		$name   = $user->display_name;
		$bio    = (string) get_user_meta( $user_id, 'description', true );
		$avatar = get_avatar( $user_id, 64, '', $user->display_name, [ 'class' => 'lrn-instructor-widget__avatar' ] );

		$instructor_stats = Course::get_instructor_stats( $user_id );
		$course_count     = (int) $instructor_stats->course_count;

		echo '<div class="lrn-instructor-card lrn-instructor-widget">';
		echo '<div class="lrn-instructor-widget__avatar-wrap">' . $avatar . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="lrn-instructor-widget__info">';
		echo '<strong class="lrn-instructor-widget__name">' . esc_html( $name ) . '</strong>';
		if ( $bio ) {
			echo '<p class="lrn-instructor-widget__bio">' . esc_html( $bio ) . '</p>';
		}
		echo '<span class="lrn-instructor-widget__courses">';
		echo esc_html(
			sprintf(
				/* translators: %d: number of courses */
				_n( '%d course', '%d courses', $course_count, 'learnomy' ),
				$course_count
			)
		);
		echo '</span>';
		echo '</div>';
		echo '</div>';

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$output = ob_get_clean();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered widget output.
		echo apply_filters( 'learnomy_widget_output', $output, $args, $instance, $this->id_base );
	}

	/**
	 * @param array $instance Saved widget settings.
	 */
	public function form( $instance ): void {
		$title   = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Featured Instructor', 'learnomy' );
		$user_id = isset( $instance['user_id'] ) ? absint( $instance['user_id'] ) : 0;
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
			<label for="<?php echo esc_attr( $this->get_field_id( 'user_id' ) ); ?>">
				<?php esc_html_e( 'Instructor user ID:', 'learnomy' ); ?>
			</label>
			<input
				class="tiny-text"
				id="<?php echo esc_attr( $this->get_field_id( 'user_id' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'user_id' ) ); ?>"
				type="number"
				min="1"
				value="<?php echo esc_attr( $user_id ); ?>">
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
			'title'   => sanitize_text_field( $new_instance['title'] ?? '' ),
			'user_id' => absint( $new_instance['user_id'] ?? 0 ),
		];
	}
}
