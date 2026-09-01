<?php
/**
 * Migration 3.2.0 — single source of truth for the default commission rate.
 *
 * Historically TWO places claimed to be "the default commission split": the
 * `learnomy_settings[platform_commission]` field (Settings → Commissions) and a
 * `rule_type='global'` row in `lrn_commission_rules` (Earnings → Commission
 * Rules). The engine silently used the global rule (via CommissionRule::
 * get_for_entity()'s global fallback), so editing one had no visible relation
 * to the other — a money-confusing split brain.
 *
 * Per the 2026-05-30 decision the Settings field is the single source. To avoid
 * changing any live payout, copy the EFFECTIVE value (the global rule's rate,
 * which is what the engine actually applied) into the Settings field, then drop
 * the global rule. Per-course and per-instructor override rules are untouched.
 *
 * Forward-only and idempotent: once the global row is gone this is a no-op.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

use Learnomy\Models\CommissionRule;

defined( 'ABSPATH' ) || exit;

class Migration_3_2_0 {

	public function up(): void {
		$global = CommissionRule::get_global();
		if ( ! $global ) {
			return;
		}

		$rate = (float) $global->platform_rate;
		if ( $rate >= 0.0 && $rate <= 100.0 ) {
			$settings = get_option( 'learnomy_settings', array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}
			// Preserve the value the engine actually applied — no payout change.
			$settings['platform_commission'] = $rate;
			update_option( 'learnomy_settings', $settings );
		}

		// Remove the now-redundant global rule so Settings is the only default.
		CommissionRule::delete( (int) $global->id );
	}
}
