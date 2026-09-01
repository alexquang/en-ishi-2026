<?php
/**
 * Migration 3.27.0 — freeze each install's effective module set before the
 * default-on list gets leaner.
 *
 * `Module_Kernel::is_enabled()` treats `defaults()` as a FLOOR: it merges the
 * default-on list into the stored `learnomy_modules_enabled` option on every
 * read. That is what lets a new release ship a module as on-by-default and have
 * it light up on existing sites.
 *
 * The same merge makes REMOVING a slug from defaults() a silent downgrade. A
 * site that never opened the Modules page has an option row that does not list
 * the default-on modules at all — on this project's own dev install the stored
 * value was `["x_fake_module","membership","commissions","coupons"]`, so
 * certificates / reviews / search / seo / abilities were resolving to ON purely
 * through the merge. Drop one of those from defaults() with no migration and
 * five working features vanish from that site on upgrade, with no notice and
 * nothing in the UI to explain it.
 *
 * So: before defaults() changes, resolve what each install is ACTUALLY running
 * and write it down explicitly. After this runs the stored option is
 * self-sufficient — the merge can no longer be the only thing keeping a module
 * alive, and defaults() is free to describe fresh installs only.
 *
 * Forward-only and idempotent. It unions (never subtracts), so re-running is a
 * no-op, and an explicit opt-out in `learnomy_modules_disabled` still wins on
 * read exactly as before — this migration deliberately does not touch it.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_27_0 {

	/**
	 * The default-on module list as it stood in 1.7.0, immediately BEFORE
	 * `certificates` was removed from it.
	 *
	 * Hardcoded on purpose. Calling Module_Kernel::defaults() here would read
	 * the NEW, leaner list — the very list this migration exists to protect
	 * sites from — and would therefore drop `certificates` while claiming to
	 * preserve it. A migration is a snapshot of history; it must not depend on
	 * code that keeps moving.
	 *
	 * @return string[]
	 */
	private function defaults_before_3_27_0(): array {
		return array(
			'courses',
			'enrollments',
			'progress',
			'quizzes',
			'certificates',
			'reviews',
			'notifications',
			'search',
			'seo',
			'abilities',
			'payments',
		);
	}

	public function up(): void {
		// A fresh install has no option row yet; activation seeds it from the
		// (new, lean) defaults and there is nothing to preserve.
		$stored = get_option( 'learnomy_modules_enabled', null );
		if ( null === $stored ) {
			return;
		}

		$stored = array_values( array_filter( array_map( 'strval', (array) $stored ) ) );

		// The set the site is running RIGHT NOW, resolved through the same merge
		// is_enabled() applies. Read before any defaults() change lands, which is
		// why this must ship in the SAME release as the leaner defaults and not
		// after it.
		$effective = array_values(
			array_unique(
				array_merge( $this->defaults_before_3_27_0(), $stored )
			)
		);

		// Drop slugs that are no longer modules. `search` and `seo` were retired
		// in 1.7.1: neither was ever read by anything, because both are mandatory
		// infrastructure (FULLTEXT lives in the models; the schema + sitemap
		// classes are instantiated unconditionally), so the toggles offered a
		// choice that did not exist. The kernel ignores unknown slugs, so leaving
		// them would be harmless — but an option row that lists modules the
		// product no longer has is exactly the kind of drift that makes the next
		// reader distrust the data.
		$retired   = array( 'search', 'seo' );
		$effective = array_values( array_diff( $effective, $retired ) );

		// Union only, so a slug the owner explicitly opted out of still lives in
		// `learnomy_modules_disabled` and keeps winning on read — writing it here
		// cannot turn it back on.
		sort( $effective );
		sort( $stored );
		if ( $effective === $stored ) {
			return;
		}

		update_option( 'learnomy_modules_enabled', $effective );

		// Same cleanup on the opt-out list, so a retired slug cannot linger as a
		// permanent "off" for a module that no longer exists.
		$disabled = (array) get_option( 'learnomy_modules_disabled', array() );
		$pruned   = array_values( array_diff( $disabled, $retired ) );
		if ( $pruned !== $disabled ) {
			update_option( 'learnomy_modules_disabled', $pruned );
		}
	}
}
