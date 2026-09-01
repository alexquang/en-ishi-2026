<?php
/**
 * Shortcodes — embed LMS content anywhere on the site.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Instructor;
use Learnomy\Models\Quiz;
use Learnomy\Models\Certificate;
use Learnomy\Models\Category;
use Learnomy\Services\View_Data\Membership_Pricing_View_Data;

class Shortcodes {

	/**
	 * Shortcode tag => the asset surface it renders.
	 *
	 * A shortcode drops Learnomy markup on a page the router never sees, so it
	 * has to ask Template_Loader for the assets that markup needs. A ROUTE name
	 * means "give me exactly what that route loads" (the shortcode renders that
	 * route's template); a light surface key means the fragment needs a slice.
	 *
	 * Keep this beside the add_shortcode() calls: a new shortcode without a row
	 * here is a shortcode that renders unstyled on a customer's page.
	 *
	 * @var array<string, string>
	 */
	private const SHORTCODE_SURFACES = array(
		// Renders templates/partials/course-card.php — cards carry Lucide icons
		// and the wishlist heart store.
		'learnomy_course'             => 'course-card',
		'learnomy_course_grid'        => 'course-card',
		'learnomy_my_courses'         => 'course-card',
		// Renders templates/membership-pricing.php — the SAME template
		// /membership/ renders, so it takes the same bundle (membership.css,
		// membership-view.js for the billing toggle + coupon field, the
		// Interactivity runtime those bind to, the icon set).
		'learnomy_membership_pricing' => 'membership-pricing',
		// Self-contained card / form fragments.
		'learnomy_enrollment_button'  => 'embed',
		'learnomy_progress'           => 'embed',
		'learnomy_quiz'               => 'embed',
		'learnomy_certificate_verify' => 'embed',
		'learnomy_instructor'         => 'embed',
		'learnomy_search'             => 'embed',
	);

	public function __construct() {
		add_shortcode( 'learnomy_course', array( $this, 'course' ) );
		add_shortcode( 'learnomy_course_grid', array( $this, 'course_grid' ) );
		add_shortcode( 'learnomy_enrollment_button', array( $this, 'enrollment_button' ) );
		add_shortcode( 'learnomy_progress', array( $this, 'progress' ) );
		add_shortcode( 'learnomy_my_courses', array( $this, 'my_courses' ) );
		add_shortcode( 'learnomy_quiz', array( $this, 'quiz' ) );
		add_shortcode( 'learnomy_certificate_verify', array( $this, 'certificate_verify' ) );
		add_shortcode( 'learnomy_membership_pricing', array( $this, 'membership_pricing' ) );
		add_shortcode( 'learnomy_instructor', array( $this, 'instructor' ) );
		add_shortcode( 'learnomy_search', array( $this, 'search_form' ) );
	}

	// -------------------------------------------------------------------------
	// [learnomy_course id="123"]
	// -------------------------------------------------------------------------

	/**
	 * Renders a single course card.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function course( $atts ): string {
		$atts = shortcode_atts(
			array( 'id' => 0 ),
			$atts,
			'learnomy_course'
		);

		$course_id = absint( $atts['id'] );
		if ( ! $course_id ) {
			return '';
		}

		// Private-academy gate: on a "require login for all content" site an
		// anonymous viewer must not get course data through a shortcode either.
		// Same gate the /courses/ archive, the catalog blocks and the course
		// sitemap enforce — one rule, every public course surface.
		$gate_notice = course_listing_gate_notice();
		if ( null !== $gate_notice ) {
			return '<div class="lrn-course-card-wrap">' . $gate_notice . '</div>';
		}

		$course = Course::find( $course_id );
		if ( ! $course || 'published' !== $course->status ) {
			return '';
		}

		$enrollment = null;
		$user_id    = get_current_user_id();
		if ( $user_id ) {
			$enrollment = Enrollment::find_for_user_course( $user_id, $course_id );
		}

		$this->enqueue_assets_for( 'learnomy_course' );

		ob_start();
		include \Learnomy\template_path( 'partials/course-card.php' );
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [learnomy_course_grid category="marketing" limit="6" columns="3"]
	// -------------------------------------------------------------------------

	/**
	 * Renders a grid of course cards, optionally filtered by category slug.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function course_grid( $atts ): string {
		$atts = shortcode_atts(
			array(
				'category' => '',
				'limit'    => 6,
				'columns'  => 3,
			),
			$atts,
			'learnomy_course_grid'
		);

		$limit    = absint( $atts['limit'] ) ?: 6;
		$columns  = absint( $atts['columns'] ) ?: 3;
		$cat_slug = sanitize_text_field( $atts['category'] );

		// Private-academy gate — see the note on course() above.
		$gate_notice = course_listing_gate_notice();
		if ( null !== $gate_notice ) {
			return '<div class="lrn-course-grid">' . $gate_notice . '</div>';
		}

		if ( '' !== $cat_slug ) {
			$category = Category::find_by_slug( $cat_slug );
			if ( ! $category ) {
				return '';
			}
			$courses = Course::get_published_by_category( (int) $category->id, $limit );
		} else {
			$courses = Course::list_published( 1, $limit );
		}

		if ( ! $courses ) {
			return '';
		}

		$user_id = get_current_user_id();

		// Batch-fetch enrollments for the whole grid in one query instead of
		// calling Enrollment::find_for_user_course() once per course (N+1).
		// get_distinct_for_user caps at count($courses) and returns one row
		// per distinct course_id, keyed by course_id after the loop.
		$enrollment_map = array();
		if ( $user_id ) {
			$user_enrs = Enrollment::get_distinct_for_user( $user_id, count( $courses ) );
			foreach ( $user_enrs as $enr ) {
				$enrollment_map[ (int) $enr->course_id ] = $enr;
			}
		}

		$this->enqueue_assets_for( 'learnomy_course_grid' );

		ob_start();
		echo '<div class="lrn-grid lrn-grid--cols-' . esc_attr( $columns ) . '">';
		foreach ( $courses as $course ) {
			$enrollment = $enrollment_map[ (int) $course->id ] ?? null;
			include \Learnomy\template_path( 'partials/course-card.php' );
		}
		echo '</div>';
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [learnomy_enrollment_button course_id="123" text="Enroll Now"]
	// -------------------------------------------------------------------------

	/**
	 * Renders an enroll/buy button, or an "Already enrolled" state.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function enrollment_button( $atts ): string {
		$atts = shortcode_atts(
			array(
				'course_id' => 0,
				'text'      => __( 'Enroll Now', 'learnomy' ),
			),
			$atts,
			'learnomy_enrollment_button'
		);

		$course_id = absint( $atts['course_id'] );
		$btn_text  = sanitize_text_field( $atts['text'] );

		if ( ! $course_id ) {
			return '';
		}

		$course = Course::find( $course_id );
		if ( ! $course || 'published' !== $course->status ) {
			return '';
		}

		$user_id    = get_current_user_id();
		$enrolled   = $user_id && Enrollment::is_enrolled( $user_id, $course_id );
		$course_url = route_url( 'course', array( 'slug' => $course->slug ) );

		$this->enqueue_assets_for( 'learnomy_enrollment_button' );

		ob_start();
		if ( $enrolled ) {
			echo '<a href="' . esc_url( $course_url ) . '" class="lrn-btn lrn-btn--secondary lrn-enrollment-btn lrn-enrollment-btn--enrolled">'
				. esc_html__( 'Already Enrolled', 'learnomy' )
				. '</a>';
		} elseif ( ! $user_id ) {
			echo '<a href="' . esc_url( wp_login_url( $course_url ) ) . '" class="lrn-btn lrn-btn--primary lrn-enrollment-btn">'
				. esc_html( $btn_text )
				. '</a>';
		} else {
			$enroll_url = add_query_arg(
				array(
					'lrn_action' => 'enroll',
					'course_id'  => $course_id,
					'_wpnonce'   => wp_create_nonce( 'lrn_enroll_' . $course_id ),
				),
				$course_url
			);
			echo '<a href="' . esc_url( $enroll_url ) . '" class="lrn-btn lrn-btn--primary lrn-enrollment-btn">'
				. esc_html( $btn_text )
				. '</a>';
		}
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [learnomy_progress course_id="123"]
	// -------------------------------------------------------------------------

	/**
	 * Renders a progress bar for the current user on a given course.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function progress( $atts ): string {
		$atts = shortcode_atts(
			array( 'course_id' => 0 ),
			$atts,
			'learnomy_progress'
		);

		$course_id = absint( $atts['course_id'] );
		$user_id   = get_current_user_id();

		if ( ! $course_id || ! $user_id ) {
			return '';
		}

		$enrollment = Enrollment::find_for_user_course( $user_id, $course_id );
		if ( ! $enrollment ) {
			return '';
		}

		$percent = (int) $enrollment->progress_pct;

		$this->enqueue_assets_for( 'learnomy_progress' );

		ob_start();
		include \Learnomy\template_path( 'partials/progress-bar.php' );
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [learnomy_my_courses limit="10"]
	// -------------------------------------------------------------------------

	/**
	 * Renders the current user's enrolled courses with progress.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function my_courses( $atts ): string {
		$atts = shortcode_atts(
			array( 'limit' => 10 ),
			$atts,
			'learnomy_my_courses'
		);

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '<p class="lrn-notice">'
				. esc_html__( 'Please log in to see your courses.', 'learnomy' )
				. '</p>';
		}

		$limit = absint( $atts['limit'] ) ?: 10;

		// Pass $limit to the model so the SQL LIMIT clause does the slice —
		// get_distinct_for_user avoids duplicate cards when a student holds
		// multiple enrollment sources for the same course (CF-007).
		$enrollments = Enrollment::get_distinct_for_user( $user_id, $limit );

		if ( ! $enrollments ) {
			return '<p class="lrn-notice">'
				. esc_html__( 'You have not enrolled in any courses yet.', 'learnomy' )
				. '</p>';
		}

		// Batch-fetch all courses in one query — Course::find_in() returns
		// an id => row map, eliminating the N+1 from Course::find() per row.
		$course_ids = array_map( fn( $e ) => (int) $e->course_id, $enrollments );
		$course_map = Course::find_in( $course_ids );
		$enr_by_cid = array();
		foreach ( $enrollments as $enr ) {
			$enr_by_cid[ (int) $enr->course_id ] = $enr;
		}

		$this->enqueue_assets_for( 'learnomy_my_courses' );

		ob_start();
		echo '<div class="lrn-my-courses lrn-grid lrn-grid--cols-3">';
		foreach ( $course_map as $course ) {
			// NO status filter — exact parity with /account/courses/
			// (Account_Courses_View_Data) and the my-progress/student-dashboard
			// blocks. An enrolled student's course stays in their list even
			// when the owner unpublishes it; the old 'published'-only guard
			// here rendered a FALSE "not enrolled in any courses yet" empty
			// state for students whose course went to draft (1.8.0 combo
			// smoke, C.shortcode-render.my-courses). Enrolled students also
			// keep the course page itself — see Single_Course_View_Data.
			$enrollment = $enr_by_cid[ (int) $course->id ] ?? null;
			include \Learnomy\template_path( 'partials/course-card.php' );
		}
		echo '</div>';
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [learnomy_quiz id="456"]
	// -------------------------------------------------------------------------

	/**
	 * Embeds the quiz start interface.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function quiz( $atts ): string {
		$atts = shortcode_atts(
			array( 'id' => 0 ),
			$atts,
			'learnomy_quiz'
		);

		$quiz_id = absint( $atts['id'] );
		if ( ! $quiz_id ) {
			return '';
		}

		$quiz = Quiz::find( $quiz_id );
		if ( ! $quiz ) {
			return '';
		}

		$user_id = get_current_user_id();

		// Embedding this shortcode for a course quiz used to build the
		// standalone URL unconditionally, so the Start Quiz button 404'd for
		// the normal case. The route choice, orphan guard included, now lives
		// in one place — see Quiz::url().
		$quiz_url  = Quiz::url( $quiz );
		$start_url = add_query_arg( 'start', '1', $quiz_url );

		$this->enqueue_assets_for( 'learnomy_quiz' );

		ob_start();
		?>
		<div class="lrn-quiz-embed lrn-card">
			<h3 class="lrn-quiz-embed__title"><?php echo esc_html( $quiz->title ); ?></h3>

			<?php if ( ! empty( $quiz->description ) ) : ?>
				<p class="lrn-quiz-embed__description"><?php echo esc_html( $quiz->description ); ?></p>
			<?php endif; ?>

			<div class="lrn-quiz-embed__meta">
				<?php
				if ( ! empty( $quiz->time_limit_seconds ) ) :
					$tl_mins = (int) ceil( (int) $quiz->time_limit_seconds / 60 );
					?>
					<span class="lrn-quiz-embed__meta-item">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of minutes */
								_n( '%d minute', '%d minutes', $tl_mins, 'learnomy' ),
								$tl_mins
							)
						);
						?>
					</span>
				<?php endif; ?>

				<?php // `passing_score` is the column; `pass_percentage` never existed, so this line never rendered. ?>
				<?php if ( ! empty( $quiz->passing_score ) ) : ?>
					<span class="lrn-quiz-embed__meta-item">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: passing percentage */
								__( '%d%% to pass', 'learnomy' ),
								(int) $quiz->passing_score
							)
						);
						?>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( $user_id ) : ?>
				<a href="<?php echo esc_url( $start_url ); ?>" class="lrn-btn lrn-btn--primary">
					<?php esc_html_e( 'Start Quiz', 'learnomy' ); ?>
				</a>
			<?php else : ?>
				<a href="<?php echo esc_url( wp_login_url( $quiz_url ) ); ?>" class="lrn-btn lrn-btn--primary">
					<?php esc_html_e( 'Log In to Take Quiz', 'learnomy' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [learnomy_certificate_verify uuid="abc-123"]
	// -------------------------------------------------------------------------

	/**
	 * Displays a certificate verification badge for the given UUID.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function certificate_verify( $atts ): string {
		$atts = shortcode_atts(
			array( 'uuid' => '' ),
			$atts,
			'learnomy_certificate_verify'
		);

		$uuid = sanitize_text_field( $atts['uuid'] );
		if ( '' === $uuid ) {
			return '';
		}

		$certificate = Certificate::find_by_uuid( $uuid );
		$is_valid    = $certificate && 'active' === $certificate->status;

		$this->enqueue_assets_for( 'learnomy_certificate_verify' );

		ob_start();
		if ( $is_valid ) {
			$student      = get_userdata( (int) $certificate->user_id );
			$student_name = $student ? $student->display_name : __( 'Unknown', 'learnomy' );
			$course       = Course::find( (int) $certificate->course_id );
			$course_name  = $course ? $course->title : __( 'Course no longer available', 'learnomy' );
			$issued_date  = esc_html( date_i18n( get_option( 'date_format' ), strtotime( $certificate->issued_at ) ) );
			?>
			<div class="lrn-certificate-badge lrn-certificate-badge--valid lrn-card">
				<i data-lucide="check-circle" class="lrn-certificate-badge__icon" aria-hidden="true"></i>
				<div class="lrn-certificate-badge__body">
					<p class="lrn-certificate-badge__status">
						<?php esc_html_e( 'Certificate Verified', 'learnomy' ); ?>
					</p>
					<p class="lrn-certificate-badge__detail">
						<?php echo esc_html( $student_name ); ?>
						&mdash;
						<?php echo esc_html( $course_name ); ?>
					</p>
					<p class="lrn-certificate-badge__date">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: date the certificate was issued */
								__( 'Issued %s', 'learnomy' ),
								$issued_date
							)
						);
						?>
					</p>
				</div>
			</div>
			<?php
		} else {
			?>
			<div class="lrn-certificate-badge lrn-certificate-badge--invalid lrn-card">
				<i data-lucide="x-circle" class="lrn-certificate-badge__icon" aria-hidden="true"></i>
				<p class="lrn-certificate-badge__status">
					<?php esc_html_e( 'Certificate Not Found', 'learnomy' ); ?>
				</p>
			</div>
			<?php
		}
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [learnomy_membership_pricing]
	// -------------------------------------------------------------------------

	/**
	 * Renders the membership plan comparison table.
	 *
	 * @param array $atts Shortcode attributes (none currently used).
	 * @return string HTML output.
	 */
	public function membership_pricing( $atts ): string {
		$this->enqueue_assets_for( 'learnomy_membership_pricing' );

		// Resolve all view-data through the canonical service layer — the
		// template is zero-logic and expects these variables to be extracted.
		// Skipping the resolver was the root cause of [learnomy_membership_pricing]
		// always rendering "No plans available yet" (plans was always []).
		$view_data = Membership_Pricing_View_Data::resolve( array( 'route' => 'membership-pricing' ) );
		$route     = 'membership-pricing'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// phpcs:disable WordPress.PHP.DontExtract.extract_extract
		extract( $view_data );
		// phpcs:enable WordPress.PHP.DontExtract.extract_extract

		ob_start();
		include \Learnomy\template_path( 'membership-pricing.php' );
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [learnomy_instructor user_id="5"]
	// -------------------------------------------------------------------------

	/**
	 * Renders an instructor card with avatar, bio, and course count.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function instructor( $atts ): string {
		$atts = shortcode_atts(
			array( 'user_id' => 0 ),
			$atts,
			'learnomy_instructor'
		);

		$user_id    = absint( $atts['user_id'] );
		$instructor = $user_id ? get_userdata( $user_id ) : null;

		// Any user id rendered an "instructor" card with that member's name,
		// avatar and bio, plus a View Profile button — so a typo'd or guessed id
		// published a student to every visitor of the page. A card renders only
		// for someone who has a public instructor profile; viewer 0 so what the
		// site owner previews is exactly what a visitor gets.
		if ( ! $instructor || ! Instructor::has_public_profile( $user_id, 0 ) ) {
			return '';
		}

		$instructor_stats = Course::get_instructor_stats( $user_id );
		$course_count     = (int) $instructor_stats->course_count;

		$avatar_url  = get_avatar_url( $user_id, array( 'size' => 80 ) );
		$bio         = get_user_meta( $user_id, 'description', true );
		$profile_url = \Learnomy\route_url( 'instructor-profile', array( 'slug' => (string) $instructor->user_nicename ) );

		$this->enqueue_assets_for( 'learnomy_instructor' );

		ob_start();
		?>
		<div class="lrn-instructor-card lrn-card">
			<div class="lrn-instructor-card__avatar-wrap">
				<img class="lrn-instructor-card__avatar"
					src="<?php echo esc_url( $avatar_url ); ?>"
					alt="<?php echo esc_attr( $instructor->display_name ); ?>"
					width="80" height="80" loading="lazy">
			</div>
			<div class="lrn-instructor-card__body">
				<h3 class="lrn-instructor-card__name">
					<a href="<?php echo esc_url( $profile_url ); ?>">
						<?php echo esc_html( $instructor->display_name ); ?>
					</a>
				</h3>

				<?php if ( $course_count > 0 ) : ?>
					<p class="lrn-instructor-card__meta">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of courses */
								_n( '%d course', '%d courses', $course_count, 'learnomy' ),
								$course_count
							)
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( $bio ) : ?>
					<p class="lrn-instructor-card__bio">
						<?php echo esc_html( wp_trim_words( $bio, 20 ) ); ?>
					</p>
				<?php endif; ?>

				<a href="<?php echo esc_url( $profile_url ); ?>" class="lrn-btn lrn-btn--ghost lrn-btn--sm">
					<?php esc_html_e( 'View Profile', 'learnomy' ); ?>
				</a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [learnomy_search placeholder="Search courses…"]
	// -------------------------------------------------------------------------

	/**
	 * Renders a search form that targets the Learnomy catalog.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function search_form( $atts ): string {
		$atts = shortcode_atts(
			array( 'placeholder' => __( 'Search courses…', 'learnomy' ) ),
			$atts,
			'learnomy_search'
		);

		$placeholder = sanitize_text_field( $atts['placeholder'] );
		$action_url  = route_url( 'catalog' );
		$current_q   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->enqueue_assets_for( 'learnomy_search' );

		ob_start();
		?>
		<form role="search" method="get" action="<?php echo esc_url( $action_url ); ?>" class="lrn-search-form">
			<label for="lrn-search-input" class="screen-reader-text">
				<?php esc_html_e( 'Search courses', 'learnomy' ); ?>
			</label>
			<div class="lrn-search-form__row">
				<input
					id="lrn-search-input"
					type="search"
					name="s"
					class="lrn-input lrn-search-form__input"
					value="<?php echo esc_attr( $current_q ); ?>"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					autocomplete="off"
				>
				<button type="submit" class="lrn-btn lrn-btn--primary lrn-search-form__btn">
					<i data-lucide="search" aria-hidden="true"></i>
					<span class="screen-reader-text"><?php esc_html_e( 'Search', 'learnomy' ); ?></span>
				</button>
			</div>
		</form>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Ask the Template Loader for the assets this shortcode's markup needs.
	 *
	 * This used to enqueue learnomy.css and nothing else, which is why
	 * [learnomy_membership_pricing] rendered an unstyled table with an inert
	 * billing-period toggle and no icons on the very page a site owner sells
	 * from. The map above routes each tag to the same dispatcher the canonical
	 * route runs, so there is no second asset list to drift.
	 *
	 * @param string $tag Shortcode tag, a key of self::SHORTCODE_SURFACES.
	 */
	private function enqueue_assets_for( string $tag ): void {
		Template_Loader::enqueue_for_surface( self::SHORTCODE_SURFACES[ $tag ] );
	}
}
