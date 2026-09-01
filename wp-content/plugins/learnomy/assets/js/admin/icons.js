/**
 * Lucide icon system for Learnomy admin.
 *
 * Replaces <i data-lucide="icon-name"></i> elements with inline SVGs
 * on DOMContentLoaded. Loaded globally on all Learnomy admin pages.
 *
 * Lucide icons used in Learnomy admin:
 *
 * Dashboard stats: book-open, users, bar-chart-3, award, star, clipboard-list
 * Navigation:      layout-dashboard, grid-2x2, gauge
 * Frontend links:  grid-2x2, gauge, briefcase, ticket, file-text
 * Actions:         plus, pencil, trash-2, search, filter, download, upload
 * Status:          check-circle, alert-circle, clock, x-circle, shield-check
 * Content:         play, image, code, list, table, file-text, link
 * People:          user, users, user-check, user-plus, graduation-cap
 * Commerce:        credit-card, receipt, wallet, badge-percent
 *
 * @package Learnomy
 */

( function () {
	'use strict';

	/**
	 * Return an <i> placeholder element that Lucide will replace with an SVG.
	 *
	 * Usage: window.lrnIcon( 'book-open' )
	 *        window.lrnIcon( 'star', 'lrn-icon--lg' )
	 *
	 * @param {string} name      Lucide icon name.
	 * @param {string} [classes] Optional extra CSS classes.
	 * @return {string} HTML string.
	 */
	window.lrnIcon = function ( name, classes ) {
		var cls = classes ? ' class="' + classes + '"' : '';
		return '<i data-lucide="' + name + '"' + cls + '></i>';
	};

	// Conversion itself lives in the shared lucide-live.js seam (initial pass
	// + MutationObserver for late renders); this file only ships lrnIcon().
} )();
