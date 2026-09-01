<?php
/**
 * Migration source registry.
 *
 * Sources self-register on the `learnomy_migration_sources` filter. Adding a new
 * source = ship a module class + register it here (or via the filter from Pro / a
 * third party). The engine never changes.
 *
 * @package Learnomy
 */

namespace Learnomy\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the registered migration sources, keyed by slug.
 */
class Source_Registry {

	/**
	 * Memoized source list, keyed by slug.
	 *
	 * @var array<string,Migration_Source>|null
	 */
	private static ?array $sources = null;

	/**
	 * All registered sources, keyed by slug.
	 *
	 * @return array<string,Migration_Source>
	 */
	public static function all(): array {
		if ( null !== self::$sources ) {
			return self::$sources;
		}

		$defaults = array(
			new Sources\Learndash_Source(),
			new Sources\Tutor_Source(),
			new Sources\Lifterlms_Source(),
		);

		/**
		 * Filter the migration sources. Pro and third parties append modules.
		 *
		 * @param Migration_Source[] $defaults
		 */
		$list = apply_filters( 'learnomy_migration_sources', $defaults );

		self::$sources = array();
		foreach ( $list as $source ) {
			if ( $source instanceof Migration_Source ) {
				self::$sources[ $source->slug() ] = $source;
			}
		}

		return self::$sources;
	}

	/**
	 * One registered source by slug, or null if unknown.
	 *
	 * @param string $slug Source slug.
	 * @return Migration_Source|null
	 */
	public static function get( string $slug ): ?Migration_Source {
		$all = self::all();
		return $all[ $slug ] ?? null;
	}

	/**
	 * Sources whose plugin is present + has data (for the wizard's source picker).
	 *
	 * @return array<string,Migration_Source>
	 */
	public static function available(): array {
		return array_filter(
			self::all(),
			static fn( Migration_Source $s ): bool => $s->is_available()
		);
	}
}
