<?php
/**
 * Instructor profile — /account/instructor-profile/
 *
 * Self-service edit of the instructor's public profile (headline, bio, expertise,
 * links). Saves via REST (POST learnomy/v1/account/instructor-profile) from
 * account-instructor-profile.js, writing the FREE lrn_instructors row the public
 * profile reads. Closes the other half of F4 (no edit path after approval).
 *
 * View-data (from Account_Instructor_Profile_View_Data): $headline, $bio,
 * $expertise, $website_url, $twitter_handle, $linkedin_url, $profile_url,
 * $rest_url, $rest_nonce.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$headline       = isset( $headline ) ? (string) $headline : '';
$bio            = isset( $bio ) ? (string) $bio : '';
$expertise      = isset( $expertise ) ? (string) $expertise : '';
$website_url    = isset( $website_url ) ? (string) $website_url : '';
$twitter_handle = isset( $twitter_handle ) ? (string) $twitter_handle : '';
$linkedin_url   = isset( $linkedin_url ) ? (string) $linkedin_url : '';
$profile_url    = isset( $profile_url ) ? (string) $profile_url : '';
?>
<main class="lrn-container lrn-account-instructor-profile" id="lrn-account-instructor-profile">

	<?php
	// This is the instructor's own profile editor, reached from the teaching
	// shell (the "Edit profile" item in instructor_nav_items()). Root the trail
	// at the Instructor Dashboard, not "My Account", so the instructor stays in
	// the teaching context (BC: it used to land in the learner account rail).
	$breadcrumb_items = array(
		\Learnomy\breadcrumb_root( 'account' ),
		array(
			'label' => sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s Dashboard', 'learnomy' ), \Learnomy\instructor_label() ),
			'href'  => \Learnomy\route_url( 'instructor-dashboard' ),
		),
		array(
			'label' => sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s profile', 'learnomy' ), \Learnomy\instructor_label() ),
		),
	);
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<div class="lrn-account-layout">
		<?php
		// Render the instructor (teaching) rail, not the learner account rail —
		// the active item is the "Edit profile" key in instructor_nav_items().
		$instructor_nav_active = 'account-instructor-profile';
		require \Learnomy\template_path( 'partials/instructor-nav.php' );
		?>
		<div class="lrn-account-layout__content">

			<?php
			$account_page = array(
				'icon'     => 'id-card',
				'title'    => sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s profile', 'learnomy' ), \Learnomy\instructor_label() ),
				'subtitle' => __( 'This is what students see on your public profile and course cards.', 'learnomy' ),
			);
			require \Learnomy\template_path( 'partials/account-page-header.php' );
			?>

			<?php if ( '' !== $profile_url ) : ?>
				<p class="lrn-field__hint">
					<?php
					printf(
						/* translators: %s: link to the public profile */
						esc_html__( 'View your public profile: %s', 'learnomy' ),
						'<a href="' . esc_url( $profile_url ) . '" target="_blank" rel="noopener">' . esc_html( $profile_url ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>

			<form class="lrn-account-form" id="lrn-instructor-profile-form" novalidate>
				<div class="lrn-account-form__status" role="alert" aria-live="assertive" hidden></div>

				<section class="lrn-card lrn-account-form__section">
					<h2 class="lrn-account-form__legend"><?php esc_html_e( 'About you', 'learnomy' ); ?></h2>

					<p class="lrn-field">
						<label class="lrn-field__label" for="lrn-ip-headline"><?php esc_html_e( 'Headline', 'learnomy' ); ?></label>
						<input class="lrn-field__input" type="text" id="lrn-ip-headline" name="headline" maxlength="255" value="<?php echo esc_attr( $headline ); ?>">
						<span class="lrn-field__hint"><?php esc_html_e( 'A short professional tagline, e.g. "Senior data scientist, 10 years teaching".', 'learnomy' ); ?></span>
					</p>
					<p class="lrn-field">
						<label class="lrn-field__label" for="lrn-ip-bio"><?php esc_html_e( 'Bio', 'learnomy' ); ?></label>
						<textarea class="lrn-field__input" id="lrn-ip-bio" name="bio" rows="5"><?php echo esc_textarea( $bio ); ?></textarea>
					</p>
					<p class="lrn-field">
						<label class="lrn-field__label" for="lrn-ip-expertise"><?php esc_html_e( 'Expertise', 'learnomy' ); ?></label>
						<input class="lrn-field__input" type="text" id="lrn-ip-expertise" name="expertise" maxlength="500" value="<?php echo esc_attr( $expertise ); ?>">
						<span class="lrn-field__hint"><?php esc_html_e( 'Comma-separated skills, e.g. "Python, Machine Learning, SQL". Shown as tags.', 'learnomy' ); ?></span>
					</p>
				</section>

				<section class="lrn-card lrn-account-form__section">
					<h2 class="lrn-account-form__legend"><?php esc_html_e( 'Links', 'learnomy' ); ?></h2>

					<p class="lrn-field">
						<label class="lrn-field__label" for="lrn-ip-website"><?php esc_html_e( 'Website', 'learnomy' ); ?></label>
						<input class="lrn-field__input" type="url" id="lrn-ip-website" name="website_url" value="<?php echo esc_attr( $website_url ); ?>" placeholder="https://">
					</p>
					<p class="lrn-field">
						<label class="lrn-field__label" for="lrn-ip-twitter"><?php esc_html_e( 'Twitter / X handle', 'learnomy' ); ?></label>
						<input class="lrn-field__input" type="text" id="lrn-ip-twitter" name="twitter_handle" value="<?php echo esc_attr( $twitter_handle ); ?>" placeholder="username">
					</p>
					<p class="lrn-field">
						<label class="lrn-field__label" for="lrn-ip-linkedin"><?php esc_html_e( 'LinkedIn URL', 'learnomy' ); ?></label>
						<input class="lrn-field__input" type="url" id="lrn-ip-linkedin" name="linkedin_url" value="<?php echo esc_attr( $linkedin_url ); ?>" placeholder="https://www.linkedin.com/in/">
					</p>
				</section>

				<?php
				/**
				 * Extra fields on the instructor's own profile screen.
				 *
				 * Context is `account_instructor`, which renders what the
				 * INSTRUCTOR APPLICATION asked for — so somebody who typed a
				 * certification number into the application can correct it here
				 * instead of it being write-once. Plain account fields stay on
				 * Account details rather than appearing on both screens.
				 *
				 * @param int    $user_id The instructor editing their profile.
				 * @param string $context 'account_instructor'.
				 */
				do_action( 'learnomy_account_fields', get_current_user_id(), 'account_instructor' );
				?>

				<div class="lrn-account-form__actions">
					<button type="submit" class="lrn-btn lrn-btn--primary" data-default-label="<?php esc_attr_e( 'Save profile', 'learnomy' ); ?>">
						<?php esc_html_e( 'Save profile', 'learnomy' ); ?>
					</button>
				</div>
			</form>

		</div><!-- .lrn-account-layout__content -->
	</div><!-- .lrn-account-layout -->

</main>
