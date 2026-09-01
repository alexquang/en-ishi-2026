<?php
/**
 * Nav-menu metabox — exposes Learnomy frontend pages to Appearance → Menus.
 *
 * Learnomy renders its frontend via custom rewrite rules, not WP posts, so
 * site builders cannot find Courses / My Courses / Instructor Dashboard /
 * Membership in the Pages or Posts metaboxes when assembling a nav menu.
 * This class adds a "Learnomy Pages" metabox to the Menus screen with a
 * checklist of every public Learnomy URL. Selected items are added as
 * standard `_menu_item_type=custom` rows with the live URL — same pattern
 * WordPress uses for the "Custom Links" metabox.
 *
 * @package Learnomy
 */

namespace Learnomy\Admin;

defined( 'ABSPATH' ) || exit;

class Nav_Menu_Metabox {

	const NONCE_ACTION = 'learnomy_add_menu_items';
	const NONCE_FIELD  = 'learnomy_add_menu_items_nonce';

	public function register(): void {
		// `load-nav-menus.php` is the canonical hook for adding metaboxes on
		// the Appearance → Menus screen. `admin_init` runs before WordPress
		// finishes setting up the nav-menus context and our box gets dropped.
		add_action( 'load-nav-menus.php', array( $this, 'register_metabox' ) );
	}

	/**
	 * Register the metabox on the nav-menus admin screen.
	 */
	public function register_metabox(): void {
		add_meta_box(
			'learnomy-nav-menu-pages',
			__( 'Learnomy Pages', 'learnomy' ),
			array( $this, 'render_metabox' ),
			'nav-menus',
			'side',
			'default'
		);
	}

	/**
	 * Build the list of public Learnomy pages. Filterable so Pro extensions
	 * (cohorts, spaces, learning paths) can append their own rewrite-driven
	 * URLs without touching this class.
	 *
	 * Each entry is a row: [ 'label' => Display, 'url' => Live URL, 'key' => stable id ].
	 *
	 * @return array<int, array{label: string, url: string, key: string}>
	 */
	public function get_pages(): array {
		// key => label, in the order a site owner scans them: the public catalog,
		// the learner's account family, the instructor's teaching family, then the
		// public sign-up destinations.
		//
		// Only routes that take NO parameters can be a static menu item — a course
		// or lesson URL needs a slug, so those are deliberately absent rather than
		// missing. Flow endpoints are absent for the same reason they would never
		// be linked: /enroll/, the thank-you and cancelled pages, the offline
		// fallback and lesson attachments are steps in a journey, not destinations.
		// Putting "Thank you" in a navigation menu is not a gap this should close.
		$labels = array(
			'catalog'                    => __( 'Course Catalog', 'learnomy' ),

			// The /account/ hub root — every account section lives beneath it. It
			// was labelled "My Courses" here, so a site owner building a menu could
			// not find a "My Account" destination at all and concluded the account
			// pages did not exist (1.8.0 release-lead audit #4). The key stays
			// 'student-dashboard' so menus already built keep their URL.
			'student-dashboard'          => __( 'My Account', 'learnomy' ),
			/* translators: %s: the plural entity label, e.g. "Courses" or "Programs". */
			'account-courses'            => sprintf( __( 'My %s', 'learnomy' ), \Learnomy\course_label( true ) ),
			'account-quiz-results'       => __( 'Quiz Attempts', 'learnomy' ),
			'account-certificates'       => __( 'Certificates', 'learnomy' ),
			'account-billing'            => __( 'Order History', 'learnomy' ),
			'account-details'            => __( 'Account Details', 'learnomy' ),
			'my-spaces'                  => ( isset( \Learnomy\entity_label_defaults()['space'] ) ? sprintf( /* translators: %s: plural label for a space (e.g. "Teams"). */ __( 'My %s', 'learnomy' ), \Learnomy\entity_label( 'space', 'plural' ) ) : __( 'My Learning Spaces', 'learnomy' ) ),
			'membership-manage'          => __( 'Manage Membership', 'learnomy' ),

			// Prefixed "Instructor" throughout: in a flat menu list "Students" or
			// "Courses" on their own do not say whose, and account-courses is
			// already "My Courses".
			'instructor-dashboard'       => __( 'Instructor Dashboard', 'learnomy' ),
			'instructor-courses'         => __( 'Instructor Courses', 'learnomy' ),
			'instructor-students'        => __( 'Instructor Students', 'learnomy' ),
			'instructor-grading'         => __( 'Instructor Grading', 'learnomy' ),
			'instructor-earnings'        => __( 'Instructor Earnings', 'learnomy' ),
			'account-instructor-profile' => __( 'Instructor Profile', 'learnomy' ),

			'membership-pricing'         => __( 'Membership Pricing', 'learnomy' ),
			'become-instructor'          => __( 'Become an Instructor', 'learnomy' ),
			'register-student'           => __( 'Register as Student', 'learnomy' ),
			'register-instructor'        => __( 'Register as Instructor', 'learnomy' ),
		);

		// Drop any row whose route belongs to a disabled module. The Router 404s
		// those paths, so offering them here hands the owner a menu item to a dead
		// page — and they would have no way to tell, because the metabox row looks
		// identical either way. This is the same guard \Learnomy\instructor_nav_items()
		// applies to the teaching rail; the metabox had NO gating at all, so with
		// the membership module switched off it still offered Membership Pricing
		// and Manage Membership.
		$pages = array();
		foreach ( $labels as $key => $label ) {
			$module = \Learnomy\Routes::module_for( $key );
			if ( null !== $module && ! \Learnomy\module_enabled( $module ) ) {
				continue;
			}
			$pages[] = array(
				'key'   => $key,
				'label' => $label,
				'url'   => \Learnomy\route_url( $key ),
			);
		}

		/**
		 * Filter the list of Learnomy pages exposed in the nav-menus metabox.
		 *
		 * Pro extensions (cohorts, spaces, learning paths, user journeys)
		 * append their own rewrite-driven URLs via this filter so the metabox
		 * remains a single source of truth.
		 *
		 * @param array $pages Page rows ['key', 'label', 'url'].
		 */
		return (array) apply_filters( 'learnomy_nav_menu_pages', $pages );
	}

	/**
	 * Render the metabox body. Mirrors the shape of WordPress core's pages
	 * metabox so the standard `nav-menus.php` JavaScript ("Add to menu" button,
	 * select-all link) works without any custom JS on our side.
	 */
	public function render_metabox(): void {
		$pages = $this->get_pages();
		?>
		<div id="learnomy-nav-menu-pages" class="categorydiv">
			<div id="tabs-panel-learnomy-nav-menu-all" class="tabs-panel tabs-panel-active">
				<ul id="learnomy-nav-menu-checklist" class="categorychecklist form-no-clear">
					<?php
					$index = -1;
					foreach ( $pages as $page ) :
						--$index;
						?>
						<li>
							<label class="menu-item-title">
								<input type="checkbox"
									class="menu-item-checkbox"
									name="menu-item[<?php echo esc_attr( $index ); ?>][menu-item-object-id]"
									value="<?php echo esc_attr( $index ); ?>" />
								<?php echo esc_html( $page['label'] ); ?>
							</label>
							<input type="hidden"
								class="menu-item-db-id"
								name="menu-item[<?php echo esc_attr( $index ); ?>][menu-item-db-id]"
								value="0" />
							<input type="hidden"
								class="menu-item-object"
								name="menu-item[<?php echo esc_attr( $index ); ?>][menu-item-object]"
								value="custom" />
							<input type="hidden"
								class="menu-item-parent-id"
								name="menu-item[<?php echo esc_attr( $index ); ?>][menu-item-parent-id]"
								value="0" />
							<input type="hidden"
								class="menu-item-type"
								name="menu-item[<?php echo esc_attr( $index ); ?>][menu-item-type]"
								value="custom" />
							<input type="hidden"
								class="menu-item-title"
								name="menu-item[<?php echo esc_attr( $index ); ?>][menu-item-title]"
								value="<?php echo esc_attr( $page['label'] ); ?>" />
							<input type="hidden"
								class="menu-item-url"
								name="menu-item[<?php echo esc_attr( $index ); ?>][menu-item-url]"
								value="<?php echo esc_attr( $page['url'] ); ?>" />
							<input type="hidden"
								class="menu-item-classes"
								name="menu-item[<?php echo esc_attr( $index ); ?>][menu-item-classes]"
								value="learnomy-menu-item learnomy-menu-item-<?php echo esc_attr( $page['key'] ); ?>" />
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<p class="button-controls wp-clearfix" data-items-type="learnomy-nav-menu-pages">
				<span class="list-controls hide-if-no-js">
					<input type="checkbox"
						id="learnomy-tab-all"
						class="select-all" />
					<label for="learnomy-tab-all"><?php esc_html_e( 'Select All', 'learnomy' ); ?></label>
				</span>
				<span class="add-to-menu">
					<?php wp_nonce_field( static::NONCE_ACTION, static::NONCE_FIELD, false ); ?>
					<input type="submit"
						class="button submit-add-to-menu right"
						value="<?php esc_attr_e( 'Add to Menu', 'learnomy' ); ?>"
						name="add-learnomy-pages-menu-item"
						id="submit-learnomy-nav-menu-pages" />
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<?php
	}
}
