<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to "wp-config.php" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'wordpress' );

/** MySQL database username */
define( 'DB_USER', 'root' );

/** MySQL database password */
define( 'DB_PASSWORD', '' );

/** MySQL hostname */
define( 'DB_HOST', getenv('DB_HOST') ?: 'localhost' );

/** Database Charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The Database Collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '=?@6D&S) kV%c>LCl83@mmL*9oeekk8[ q$*0/P-|zqY7`IliNXJi0dw~c;yesNZ' );
define( 'SECURE_AUTH_KEY',  'd?sxDQbyHGw.q?jI[0@C{rWi47*qabL5k@=$RbEC:vpg4g{2GS_ 1p1?_P.dbm_V' );
define( 'LOGGED_IN_KEY',    '=uwufe->(]q]tA<VkH*?c-&p/s.aT^#<7Lq)1<!vG3>~!bBtXSN18YH:8?*s:j@d' );
define( 'NONCE_KEY',        '&06BpRUf`T2e>mDsH)}{qo$pl7ByBS({^z!L##@jh0Y!CwBEkz$!5GC]w aW(v4%' );
define( 'AUTH_SALT',        'p*@~%6<xDDQ P/(V<VXAO6qh*!;Gx01aBzC}$dx o$*uwiG>o_2@C0 :e(/jOnx0' );
define( 'SECURE_AUTH_SALT', '6P4!puy%gs{E/~!D3[<JT@94vqP{n3xwn)^qz/ZM8b](!]a:RNEj=s32ne:f.yA]' );
define( 'LOGGED_IN_SALT',   ',w=nO`g%Y^ED-c jE%mk:*Ajc|=x>9I[6Y[{/Hv- eGsr<a0.X]/429a~Rk/}*@9' );
define( 'NONCE_SALT',       'Xa!Wc7Xw1vz!`~er03RvN.XW`BKH--$<fzg~Tyuvk!osZ;9D[HuG&__s&FgVd)3)' );

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';

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
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
