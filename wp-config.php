<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'enishi' );

/** Database username */
define( 'DB_USER', 'enishi_user' );

/** Database password */
define( 'DB_PASSWORD', 'Q0941.com' );

/** Database hostname */
define( 'DB_HOST', 'mysql' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '9znv5<m=+|XoK#yCW{07g7<W!j8U>v?XZn$)?d%gp:AleO9r[swbe6]UM RKQK7A' );
define( 'SECURE_AUTH_KEY',  'm1Z<Rfh7%%`L7HB:]fR1ypOn@ig: 0:M0uH~^<{c.j*4T>^#0G>|WQYu4p(B8*|j' );
define( 'LOGGED_IN_KEY',    '0;,XRJP&E[Te|A}SzRVy_Yx9J#xNh?FX[_^g(?E(r@y|Kv~{%SG(PT,3Z,#But(s' );
define( 'NONCE_KEY',        'o [jd.~z1Mqp D{[5Z9A:[WI$,0oBzfl+cRpl@i+;;]B{g@FJ0f!V+<@H-Z4ZGE3' );
define( 'AUTH_SALT',        '_Z zm9JJN7I:E#W<{H:4j[!(xmEvd ebxw$]aL.p9zXdp#*CTKy%=-b//TPaxcx/' );
define( 'SECURE_AUTH_SALT', 'EaG7hD>jUBAg^2$3m_Z`EzS3]%YO>FyIS3%ieYX?HJsF^{2gv)JPE5Jc4QQJf$rc' );
define( 'LOGGED_IN_SALT',   'c btjx9w5d`E!p3^r)6GSWrf%00~-,HRIBu;7/fS4vnvT$g!8n*DM;`UX-4K(j5H' );
define( 'NONCE_SALT',       'XMrvjopm|&zkA;J#i3vh+{wCw~zGEMg9gOY)ewOb`|qcrJ5/7PK43pF Zo{v1Q:(' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'eni_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);
define('WP_DEBUG_DISPLAY', false);

@ini_set('display_errors', 0);
define('FS_METHOD', 'direct');

define('WP_REDIS_HOST', '45.117.170.72');
define('WP_REDIS_PORT', 6379);
define('WP_REDIS_DATABASE', 1);
define('WP_CACHE_KEY_SALT', 'dev_pt_solution_');


/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
