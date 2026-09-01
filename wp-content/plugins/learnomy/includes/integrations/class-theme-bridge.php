<?php
/**
 * Theme token bridge.
 *
 * Learnomy's UI is driven entirely by `--lrn-*` CSS custom properties. By
 * default those tokens cascade from BuddyNext / theme.json with hardcoded
 * fallbacks, and the plugin ships its own `.lrn-dark` palette toggled by a
 * floating button. That works standalone, but on a theme with its OWN
 * site-wide dark mode (e.g. Reign) the two never connect: toggling the theme's
 * dark mode does nothing to Learnomy, and Learnomy's toggle only darkens its
 * own area.
 *
 * This bridge fixes that by ALIASING the core `--lrn-*` tokens onto the active
 * theme's design tokens. Because the theme flips its own custom properties
 * under its dark selector, Learnomy then follows the theme's dark mode
 * automatically and site-wide — no plugin toggle, no second palette, no
 * knowledge of the theme's dark selector required.
 *
 * Each supported theme is one entry in maps(); the set is filterable via
 * `learnomy_theme_token_maps`, so Pro and additional themes (BuddyX, BuddyX
 * Pro, …) register their own mapping without touching this file. Pro inherits
 * the bridge for free — its surfaces use the same `--lrn-*` tokens.
 *
 * @package Learnomy
 */

declare(strict_types=1);

namespace Learnomy\Integrations;

defined( 'ABSPATH' ) || exit;

class Theme_Bridge {

	/** Resolved active map (null = none), cached per request. */
	private static ?array $active = null;

	/** Whether active_map() has run this request. */
	private static bool $resolved = false;

	/**
	 * Register the frontend hook. Called once from the plugin bootstrap.
	 */
	public static function init(): void {
		// Priority 20 so the 'learnomy' master stylesheet is already enqueued.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	/**
	 * Theme token maps. Each entry:
	 *   id     — stable identifier.
	 *   label  — human name (debug only).
	 *   active — callable returning bool: is this theme the active one?
	 *   vars   — map of `--lrn-*` token => CSS value (usually a var(--theme-*) alias).
	 *
	 * Extend via the `learnomy_theme_token_maps` filter.
	 *
	 * @return array<string, array{id:string,label:string,active:callable,vars:array<string,string>}>
	 */
	public static function maps(): array {
		$maps = array(
			'reign'  => array(
				'id'     => 'reign',
				'label'  => 'Reign',
				// REIGN_THEME_VERSION is defined by the theme for both the parent
				// and any Reign child theme, so this is child-theme safe.
				'active' => static fn(): bool => defined( 'REIGN_THEME_VERSION' ),
				'vars'   => self::reign_vars(),
			),
			'buddyx' => array(
				'id'     => 'buddyx',
				'label'  => 'BuddyX / BuddyX Pro',
				// Free BuddyX + BuddyX Pro (and BuddyX child themes) all use the
				// same `--color-theme-*` / `--global-*` token system and flip it
				// under `[data-bx-mode="dark"]`. Match on the stylesheet/template
				// slug so every BuddyX flavour is covered without a per-edition
				// constant.
				'active' => static fn(): bool => self::theme_slug_starts_with( 'buddyx' ),
				'vars'   => self::buddyx_vars(),
			),
		);

		/**
		 * Filter the theme token maps. Themes/add-ons register a mapping here so
		 * Learnomy's `--lrn-*` tokens follow that theme's palette + dark mode.
		 *
		 * @param array $maps Keyed map definitions.
		 */
		return (array) apply_filters( 'learnomy_theme_token_maps', $maps );
	}

	/**
	 * The first active theme map, or null when the active theme has no bridge.
	 */
	public static function active_map(): ?array {
		if ( self::$resolved ) {
			return self::$active;
		}
		self::$resolved = true;

		foreach ( self::maps() as $map ) {
			$cb = $map['active'] ?? null;
			if ( is_callable( $cb ) && (bool) $cb() ) {
				self::$active = $map;
				break;
			}
		}
		return self::$active;
	}

	/**
	 * Whether the active theme provides a dark-mode token bridge. Used to gate
	 * Learnomy's own floating dark toggle: when the theme drives dark mode
	 * site-wide we defer to it instead of floating a second, plugin-only switch.
	 */
	public static function has_active_bridge(): bool {
		return null !== self::active_map();
	}

	/**
	 * Emit the active theme's token aliases as inline CSS on the master
	 * stylesheet (frontend only — admin has no theme dark mode).
	 */
	public static function enqueue(): void {
		$map = self::active_map();
		if ( ! $map || empty( $map['vars'] ) ) {
			return;
		}
		wp_add_inline_style( 'learnomy', self::build_css( (array) $map['vars'] ) );
	}

	/**
	 * Build the `:root, .lrn-app { … }` alias block.
	 *
	 * @param array<string,string> $vars
	 */
	private static function build_css( array $vars ): string {
		$decls = '';
		foreach ( $vars as $name => $value ) {
			$decls .= $name . ':' . $value . ';';
		}
		return ':root,.lrn-app{' . $decls . '}';
	}

	/**
	 * Reign 8.0.0 token map.
	 *
	 * Anchor tokens alias the `--reign-*` properties the theme flips under its
	 * dark selector (`[data-bx-mode="dark"]`): body bg/text, section + secondary
	 * backgrounds, alternate text, and the theme colour. The remaining overlay
	 * tokens (hovers, borders, dividers, muted text) are DERIVED from the now
	 * theme-driven `--lrn-text` via color-mix, so they invert correctly in dark
	 * mode without a second hardcoded palette. Each alias keeps Learnomy's
	 * original value as the fallback, so a missing Reign var never breaks the UI.
	 *
	 * @return array<string,string>
	 */
	private static function reign_vars(): array {
		return array(
			// Anchor tokens → Reign vars (these flip in the theme's dark mode).
			'--lrn-bg'             => 'var(--reign-site-body-bg-color, #ffffff)',
			'--lrn-bg-secondary'   => 'var(--reign-site-sections-bg-color, #f7f7f5)',
			'--lrn-bg-tertiary'    => 'var(--reign-site-secondary-bg-color, #f0f0ed)',
			'--lrn-text'           => 'var(--reign-site-body-text-color, #37352f)',
			'--lrn-text-secondary' => 'var(--reign-site-alternate-text-color, #6b6b6b)',
			'--lrn-accent'         => 'var(--reign-colors-theme, #2383e2)',

			// Derived overlays — follow --lrn-text so they adapt to light/dark.
			'--lrn-text-tertiary'  => 'color-mix(in srgb, var(--lrn-text) 55%, transparent)',
			'--lrn-text-muted'     => 'color-mix(in srgb, var(--lrn-text) 50%, transparent)',
			'--lrn-bg-hover'       => 'color-mix(in srgb, var(--lrn-text) 5%, transparent)',
			'--lrn-bg-active'      => 'color-mix(in srgb, var(--lrn-text) 9%, transparent)',
			'--lrn-bg-muted'       => 'color-mix(in srgb, var(--lrn-text) 4%, transparent)',
			'--lrn-border'         => 'color-mix(in srgb, var(--lrn-text) 14%, transparent)',
			'--lrn-border-heavy'   => 'color-mix(in srgb, var(--lrn-text) 22%, transparent)',
			'--lrn-divider'        => 'color-mix(in srgb, var(--lrn-text) 8%, transparent)',
		);
	}

	/**
	 * BuddyX / BuddyX Pro token map (theme token system v5.1).
	 *
	 * Anchor tokens alias the `--color-theme-*` / `--global-*` properties the
	 * theme flips under its dark selector (`[data-bx-mode="dark"]`): page body,
	 * the white-box surface, the light secondary background, body + meta text,
	 * and the theme primary. The remaining overlay tokens are DERIVED from the
	 * now theme-driven `--lrn-text` via color-mix so they invert in dark mode
	 * without a second palette, except `--lrn-border` / `--lrn-divider` which
	 * use BuddyX's own border tokens (they flip too) with a color-mix fallback.
	 * Each alias keeps Learnomy's original value as the fallback, so a missing
	 * BuddyX var never breaks the UI. Covers free BuddyX and BuddyX Pro alike.
	 *
	 * @return array<string,string>
	 */
	private static function buddyx_vars(): array {
		return array(
			// Anchor tokens → BuddyX vars (these flip in the theme's dark mode).
			'--lrn-bg'             => 'var(--color-theme-body, #ffffff)',
			'--lrn-bg-secondary'   => 'var(--color-theme-white-box, #f7f7f5)',
			'--lrn-bg-tertiary'    => 'var(--global-body-lightcolor, #f0f0ed)',
			'--lrn-text'           => 'var(--global-font-color, #37352f)',
			'--lrn-text-secondary' => 'var(--color-meta, #6b6b6b)',
			'--lrn-accent'         => 'var(--color-theme-primary, #2383e2)',

			// Derived overlays — follow --lrn-text so they adapt to light/dark.
			'--lrn-text-tertiary'  => 'color-mix(in srgb, var(--lrn-text) 55%, transparent)',
			'--lrn-text-muted'     => 'color-mix(in srgb, var(--lrn-text) 50%, transparent)',
			'--lrn-bg-hover'       => 'color-mix(in srgb, var(--lrn-text) 5%, transparent)',
			'--lrn-bg-active'      => 'color-mix(in srgb, var(--lrn-text) 9%, transparent)',
			'--lrn-bg-muted'       => 'color-mix(in srgb, var(--lrn-text) 4%, transparent)',
			'--lrn-border'         => 'var(--global-border-color, color-mix(in srgb, var(--lrn-text) 14%, transparent))',
			'--lrn-border-heavy'   => 'color-mix(in srgb, var(--lrn-text) 22%, transparent)',
			'--lrn-divider'        => 'var(--color-hr, color-mix(in srgb, var(--lrn-text) 8%, transparent))',
		);
	}

	/**
	 * True when the active theme's template or stylesheet slug begins with the
	 * given prefix (covers parent + child themes without a per-theme constant).
	 */
	private static function theme_slug_starts_with( string $prefix ): bool {
		$theme = wp_get_theme();
		foreach ( array( (string) $theme->get_template(), (string) $theme->get_stylesheet() ) as $slug ) {
			if ( '' !== $slug && 0 === strpos( $slug, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
