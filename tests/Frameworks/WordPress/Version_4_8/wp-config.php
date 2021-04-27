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
 * @link https://codex.wordpress.org/Editing_wp-config.php
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'test');

/** MySQL database username */
define('DB_USER', 'test');

/** MySQL database password */
define('DB_PASSWORD', 'test');

/** MySQL hostname */
define('DB_HOST', 'mysql_integration');
//define('DB_HOST', '127.0.0.1');

/** Database Charset to use in creating database tables. */
define('DB_CHARSET', 'utf8');

/** The Database Collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY',         'Och+?I*yay#?Yb1fm]6TB<Yp^sg}f4:<Bz2/*e~eW8 ;Fy{7P9`z}~|u0?e@S,>]');
define('SECURE_AUTH_KEY',  'f+^A,7s[|V|`<iOa*z*pp9N1n]_{.a6-04bTN,E;70P |<Ex;ll5>Rgy5,Til+QQ');
define('LOGGED_IN_KEY',    '%5Y|-G)I`(-L%n.&Sbw^=,W-&HN+Nm:m-N|r-w`I;]0(S#T2vzTsBsV(>d3]>a+_');
define('NONCE_KEY',        '|ypagm%:^#<o]U.h,AO^u=<|*:*dzMQQ!wwxWvAf[yC3-vto;NuMyYE!Zu] %6UU');
define('AUTH_SALT',        'qRod~M-uP9t|NlK|tw(ZZC(tHIlr-]WhK*Yo+ZLu(!679X*<4ZrhrXPn&5bk.!yu');
define('SECURE_AUTH_SALT', 'D9$NNHF:VX /bu:Vt#]*f+^?xY-:jB(z*_s$,>yEv-d?$Aoed[<2F;e(}T}z9[V|');
define('LOGGED_IN_SALT',   'yfCgl{zwMBs%rU6D%)Qw#~EArg:02(ImYI?j<xj/1NT+*xy qGc}&Q>*NQu[{W,}');
define('NONCE_SALT',       'o2ei!i)/a3xbKw%KTMpyZSbwV<Z80-?A3$Ie.s8O-5c6W#dvzfBCwYp_-58!X]_}');

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix  = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the Codex.
 *
 * @link https://codex.wordpress.org/Debugging_in_WordPress
 */
define('WP_DEBUG', false);

/** Disable internal Wp-Cron feature **/
define('DISABLE_WP_CRON', true);

/* That's all, stop editing! Happy blogging. */

/** Absolute path to the WordPress directory. */
if ( !defined('ABSPATH') )
	define('ABSPATH', dirname(__FILE__) . '/');

/** Sets up WordPress vars and included files. */
require_once(ABSPATH . 'wp-settings.php');
