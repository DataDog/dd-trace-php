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
define( 'DB_NAME', 'wp59' );

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

define( 'DISABLE_WP_CRON', true ); // disable cron

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
define( 'AUTH_KEY',         'cVf%D|kCky1)=sQcJs^4(<9><tB.hXm^yd%h&QL3Og&tLi9SMZ%<:`BpM2s,toKz' );
define( 'SECURE_AUTH_KEY',  'NkFk!%@S:xo )lr^QFWN5*9w!$Y|Dj`PSyvkLU3#ZO8^dnrZ}&TqD[/Xfw !WDEg' );
define( 'LOGGED_IN_KEY',    '4<6%?;-sYt#b?>Ol!%k-L*L^KUBZcfs07SJ*Jir[@q_3_kP~H%FftkSEV>D~RS]c' );
define( 'NONCE_KEY',        'k4y#ngW@zL@SXY-D83pfUE, ]V+~ K&JZN1nUf9^@g5L@|6F<,(2@Jl$P7?=3|kO' );
define( 'AUTH_SALT',        'm~He8$AW@@ci)|hg~#p)Qah:v.5dld)dd G<nzPOGJ=Noe8)_Sat3vV>*+^,MaK{' );
define( 'SECURE_AUTH_SALT', 'wSa ,]BJ,)tmw*6mVF<olqaBr1K)o)F4Sv+{9skd(`}P[2{!WSLNLp:};Ny>a87:' );
define( 'LOGGED_IN_SALT',   'sFtv#;7D!D?~5*Mvr}( Tq%M E>QklM0slN9XU:{5,f8AMqQJRR?^Tj6~5n=ZwK:' );
define( 'NONCE_SALT',       ' 4.U3A*5T;eT`W8s+0%/F*j(=,qi,8il,>5J`-eu}<=3.Z7?{Bgzg_HVszIPWsgC' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp55_';

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

//Appsec mock. This wont be needed on customer apps since this functions will be exposed by appsec
require __DIR__.'/../../../Appsec/Mock.php';

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
