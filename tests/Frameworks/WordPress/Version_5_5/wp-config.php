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
define( 'DB_NAME', 'wp55' );

/** MySQL database username */
define( 'DB_USER', 'test' );

/** MySQL database password */
define( 'DB_PASSWORD', 'test' );

/** MySQL hostname */
define( 'DB_HOST', 'mysql_integration' );

/** Database Charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The Database Collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

define('DISABLE_WP_CRON', 'true');

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'mdPXxC5(8DBXn /rgbdDDX9~C+3ag^ u$Q)KTccOM$-a#q-lz27)m#/wP9:,|^3G' );
define( 'SECURE_AUTH_KEY',  '3I2N#/*{RpA!svnfv[Vt;KC/+C~R+a58#2Lwww`/EaMD;{[T3jc6G$}fx^z58*I+' );
define( 'LOGGED_IN_KEY',    'q/ H:RD`0:pD/5-6mMtL@(7&edvMD3rc$OdNUqp1ig/0qzPmODaqL*N/El647HkY' );
define( 'NONCE_KEY',        '>5eIdFaE2twUZs(s)lq94xLr,&3bf-`Pc6z_5WC6y)rz8qQAdTFD/lee0<#?)^{R' );
define( 'AUTH_SALT',        'j*_$}oq?1-Po}<=NV+XscXMClsJ}XM{hH[FX(fz#zWG{2Z} |^}/%+Cr0uqHVqJ~' );
define( 'SECURE_AUTH_SALT', '[E8/Ncpus0U1}dxIS|^3}H++xDUsc%a82=y@j H]&OF,b_i pT*/t#ZdZ75cAlIy' );
define( 'LOGGED_IN_SALT',   ':fQ%E>X_a|G_FhHLB{u(q=ce#lb!Cax|i$6:6>r3&Vu6gn02DG8@SWx/$pp6kDwL' );
define( 'NONCE_SALT',       'zjQjVqfDCm<>FEneN_fiY6g_Q.,beFG1)F4XV}TgO6vw?tc_[YRb.V%45=h[!,u+' );

/**#@-*/

/**
 * WordPress Database Table prefix.
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

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

//Appsec mock. This wont be needed on customer apps since this functions will be exposed by appsec
 require __DIR__.'/../../../Appsec/Mock.php';

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
