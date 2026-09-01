<?php
/**
 * PSR-4 autoloader.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

class Autoloader {

	private static array $map = [
		'Learnomy\\Models\\'              => 'includes/models/',
		'Learnomy\\Permissions\\'         => 'includes/permissions/',
		'Learnomy\\Services\\'            => 'includes/services/',
		'Learnomy\\API\\'                 => 'includes/api/',
		'Learnomy\\Adapters\\'            => 'includes/adapters/',
		'Learnomy\\Notifications\\'       => 'includes/notifications/',
		'Learnomy\\SEO\\'                 => 'includes/seo/',
		'Learnomy\\Widgets\\'             => 'includes/widgets/',
		'Learnomy\\Admin\\Ajax\\'         => 'includes/admin/ajax/',
		'Learnomy\\Admin\\'               => 'includes/admin/',
		'Learnomy\\CLI\\'                 => 'includes/cli/',
		'Learnomy\\Services\\View_Data\\' => 'includes/services/view-data/',
		'Learnomy\\Security\\'            => 'includes/security/',
		'Learnomy\\Auth\\'                => 'includes/auth/',
		'Learnomy\\Catalog\\'             => 'includes/catalog/',
		'Learnomy\\Content\\'             => 'includes/content/',
		'Learnomy\\DB\\Migrations\\'      => 'includes/db/migrations/',
		'Learnomy\\DB\\'                  => 'includes/db/',
		'Learnomy\\Cron\\'                => 'includes/cron/',
		'Learnomy\\Modules\\'             => 'includes/modules/',
		'Learnomy\\Integrations\\'        => 'includes/integrations/',
		'Learnomy\\Migration\\Sources\\'  => 'includes/migration/sources/',
		'Learnomy\\Migration\\'           => 'includes/migration/',
		'Learnomy\\'                      => 'includes/',
	];

	public static function register(): void {
		spl_autoload_register( [ __CLASS__, 'autoload' ] );
	}

	public static function autoload( string $class ): void {
		if ( 0 !== strpos( $class, 'Learnomy\\' ) ) {
			return;
		}

		foreach ( self::$map as $prefix => $dir ) {
			if ( 0 === strpos( $class, $prefix ) ) {
				$relative = substr( $class, strlen( $prefix ) );
				$file     = LEARNOMY_DIR . $dir . 'class-' . self::class_to_file( $relative ) . '.php';

				if ( file_exists( $file ) ) {
					require_once $file;
					return;
				}

				$file = LEARNOMY_DIR . $dir . 'interface-' . self::class_to_file( $relative ) . '.php';
				if ( file_exists( $file ) ) {
					require_once $file;
					return;
				}
			}
		}
	}

	private static function class_to_file( string $class ): string {
		$file = preg_replace( '/([a-z])([A-Z])/', '$1-$2', $class );
		$file = preg_replace( '/_(?=[a-zA-Z])/', '-', $file );
		return strtolower( $file );
	}
}
