<?php
/**
 * Admin view: LMS Import wizard shell.
 *
 * Thin container — the wizard is rendered by assets/js/admin/migration-wizard.js
 * against the migration REST routes. Zero logic here (data comes from REST).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap lrn-admin-wrap lrn-migration">
	<div class="lrn-page-header">
		<div class="lrn-page-header__main">
			<span class="lrn-page-header__icon" data-lucide="download" aria-hidden="true"></span>
			<div>
				<h1 class="lrn-page-header__title"><?php esc_html_e( 'Import from another LMS', 'learnomy' ); ?></h1>
				<p class="lrn-page-header__subtitle">
					<?php esc_html_e( 'Bring courses, lessons, and quizzes from LearnDash, Tutor LMS, or LifterLMS into Learnomy. Content only. Your prices, orders, and payments are never touched.', 'learnomy' ); ?>
				</p>
			</div>
		</div>
	</div>
	<hr class="wp-header-end">

	<div id="lrn-migration-app" class="lrn-migration-app" aria-live="polite">
		<p class="lrn-migration-loading"><?php esc_html_e( 'Loading…', 'learnomy' ); ?></p>
	</div>

	<noscript>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'The import wizard needs JavaScript. You can also run an import from the command line: wp learnomy migrate <source>.', 'learnomy' ); ?>
		</p></div>
	</noscript>
</div>
