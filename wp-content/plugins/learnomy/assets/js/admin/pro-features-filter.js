/**
 * Extracted inline script from includes/admin/views/pro-features.php.
 * Enqueued via class-admin.php conditionally on the matching admin page.
 * Per ux-foundation rule F2 (no inline <script> in PHP).
 *
 * @package Learnomy
 */
(function () {
	'use strict';
	var filters = document.querySelectorAll('.lrn-pro-filter');
	var cards   = document.querySelectorAll('.lrn-pro-card');
	if (!filters.length || !cards.length) return;

	filters.forEach(function (btn) {
		btn.addEventListener('click', function () {
			var filter = btn.dataset.filter || 'all';
			filters.forEach(function (b) {
				b.classList.remove('is-active');
				b.setAttribute('aria-selected', 'false');
			});
			btn.classList.add('is-active');
			btn.setAttribute('aria-selected', 'true');

			cards.forEach(function (card) {
				var tier = card.dataset.tier || 'free';
				var show = filter === 'all' || filter === tier;
				card.style.display = show ? '' : 'none';
			});
		});
	});
})();
