<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'wp61' );

/** Database username */
define( 'DB_USER', 'test' );

/** Database password */
define( 'DB_PASSWORD', 'test' );

/** Database hostname */
define( 'DB_HOST', 'mysql_integration' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

define( 'DISABLE_WP_CRON', true );

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
define( 'AUTH_KEY',         'la},V?*,+Q!1PH^{|da(GC.7|XJF=>T(}qs@aaxhqr-$5b]a#W^wA!(/H#0=BM-z' );
define( 'SECURE_AUTH_KEY',  'Q0Iw:;{6,KT2(5>G(nn=hBZ/qZ#3(5a6l!L%TLs=Fc|s7#~k#F=;pSCVIMr;x!9I' );
define( 'LOGGED_IN_KEY',    'v{R8]43SJVZ^&F$J0E;GmdxOu1-gC/ya_2}Iu;7-7[3@y,dN*Nf2Vc-X_(vF$.HO' );
define( 'NONCE_KEY',        '%(pvJ~w<Opw-W!J5gCeolm@rXbff6cq46&0RM(@IWX;>!vj4zi58!Q)TZfX1OmvF' );
define( 'AUTH_SALT',        'v.;O~?acdJqxNLGPUm|!T.FZs7m*J8P=^PY$A!<Bd;DUQ_i%gK@Gl08p(k.94YpD' );
define( 'SECURE_AUTH_SALT', '-K?2?(NBgy~ID]1b Y{,LVn831S :FH176:`GI@OF*+R0,oIpWNf<ZPk7aPg-RTa' );
define( 'LOGGED_IN_SALT',   '`tPI_G2Ay[r5nYa+-K[~_:~{2lzD[}f):>nofoO&:r^4V9t~Lnx/LMeKbwCiw~JU' );
define( 'NONCE_SALT',       '/tey6HY^C} I-ovHhO}N5KX(7bO)35zvh;S,9HKU%{IENKx>E5hF3:aR#?A*Ibse' );

/**#@-*/

/**
 * WordPress database table prefix.
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

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';

//Appsec mock. This wont be needed on customer apps since this functions will be exposed by appsec
require __DIR__.'/../../../Appsec/Mock.php';
