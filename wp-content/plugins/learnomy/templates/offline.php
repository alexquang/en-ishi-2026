<?php
/**
 * Offline fallback page — shown when the user has no network connection
 * and the requested page is not in the Service Worker cache.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="lrn-container lrn-offline" role="main">
	<div class="lrn-offline__inner">

		<svg class="lrn-offline__icon" width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
			<line x1="1" y1="1" x2="23" y2="23"></line>
			<path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"></path>
			<path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"></path>
			<path d="M10.71 5.05A16 16 0 0 1 22.56 9"></path>
			<path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"></path>
			<path d="M8.53 16.11a6 6 0 0 1 6.95 0"></path>
			<line x1="12" y1="20" x2="12.01" y2="20"></line>
		</svg>

		<h1 class="lrn-offline__title"><?php esc_html_e( "You're offline", 'learnomy' ); ?></h1>

		<p class="lrn-offline__message">
			<?php esc_html_e( 'Your lesson progress has been saved and will sync automatically when you reconnect.', 'learnomy' ); ?>
		</p>

		<button type="button" class="lrn-btn lrn-btn--primary lrn-offline__retry">
			<?php esc_html_e( 'Try Again', 'learnomy' ); ?>
		</button>

	</div>
</div>
