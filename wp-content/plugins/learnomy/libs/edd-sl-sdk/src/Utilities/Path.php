<?php
/**
 * Path utility class.
 *
 * @package EasyDigitalDownloads\Updater
 * @since 1.0.1
 */

namespace EasyDigitalDownloads\Updater\Utilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

/**
 * Path utility class for managing SDK paths and URLs.
 */
class Path {

	/**
	 * Stores the current SDK directory.
	 *
	 * @var string
	 */
	private static $sdk_dir = '';

	/**
	 * Stores the current SDK URL.
	 *
	 * @var string
	 */
	private static $sdk_url = '';

	/**
	 * Stores the current SDK version.
	 *
	 * @var string
	 */
	private static $sdk_version = '';

	/**
	 * Sets the SDK paths and version based on a file location.
	 *
	 * @since 1.0.1
	 * @param string $file    The __FILE__ constant from the SDK main plugin file.
	 * @param string $version The version number.
	 * @return void
	 */
	public static function set( $file, $version = '1.0.0' ) {
		self::$sdk_dir     = dirname( $file );
		self::$sdk_version = $version;

		// Ask WordPress for the URL instead of reconstructing it from $_SERVER.
		//
		// The upstream SDK derived this by str_replace()-ing realpath(DOCUMENT_ROOT)
		// out of the SDK directory and gluing HTTP_HOST on the front. That breaks in
		// two ways we actually hit:
		//
		//   1. If DOCUMENT_ROOT does not prefix-match the plugin path — a resolved
		//      symlink, or any host that serves from a different root (many live
		//      hosts, LocalWP, tastewp) — nothing is stripped and the full
		//      FILESYSTEM path lands in the URL, so edd-sl-sdk.js and
		//      style-edd-sl-sdk.css 404. It does not reproduce on a plain local
		//      install, which is exactly why it shipped.
		//   2. HTTP_HOST is the request's Host header, not the site's configured
		//      address — so it is wrong behind a reverse proxy, on a subdirectory
		//      install, and on multisite/domain-mapped sites.
		//
		// plugins_url() answers both from WP's own configuration. Portfolio-wide fix,
		// first landed in BuddyNext 1.0.7 (commit 40effc78).
		self::$sdk_url = trailingslashit( plugins_url( '', $file ) );
	}

	/**
	 * Gets the SDK directory.
	 *
	 * @since 1.0.1
	 * @return string
	 */
	public static function get_dir() {
		return self::$sdk_dir;
	}

	/**
	 * Gets the SDK URL.
	 *
	 * @since 1.0.1
	 * @return string
	 */
	public static function get_url() {
		return self::$sdk_url;
	}

	/**
	 * Gets the SDK version.
	 *
	 * @since 1.0.1
	 * @return string
	 */
	public static function get_version() {
		return self::$sdk_version;
	}
}
