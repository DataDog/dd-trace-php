<?php

/* Path to the WordPress codebase you'd like to test. Add a forward slash in the end. */
define( 'ABSPATH', dirname( __FILE__ ) . '/src/' );

/*
 * Path to the theme to test with.
 *
 * The 'default' theme is symlinked from test/phpunit/data/themedir1/default into
 * the themes directory of the WordPress install defined above.
 */
define( 'WP_DEFAULT_THEME', 'default' );

// Test with multisite enabled.
// Alternatively, use the tests/phpunit/multisite.xml configuration file.
// define( 'WP_TESTS_MULTISITE', true );

// Force known bugs to be run.
// Tests with an associated Trac ticket that is still open are normally skipped.
// define( 'WP_TESTS_FORCE_KNOWN_BUGS', true );

// Test with WordPress debug mode (default).
define( 'WP_DEBUG', true );

// ** MySQL settings ** //

// This configuration file will be used by the copy of WordPress being tested.
// wordpress/wp-config.php will be ignored.

// WARNING WARNING WARNING!
// These tests will DROP ALL TABLES in the database with the prefix named below.
// DO NOT use a production database or one that is shared with something else.

define( 'DB_NAME', 'wordpress_functional_testing' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'mysql' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 */
define('AUTH_KEY',         'B?xL|x=s6|8/Yi3ZW(]MVoxO-O:8taK91C$wfDpZ$`(Zo2ak*q J^+ir9s.|qwu3');
define('SECURE_AUTH_KEY',  'SJ?)H|@e9Ezad3+LbS:j^7^lIN7-$.40-s,bM]a4{;P@cD+J8;X]jWbUaVA1<VG ');
define('LOGGED_IN_KEY',    'MJ4N7Woe^gy5{ 9?WR4S6H8Z>nns?6tMwCt#,:xeQ g,$bT9d<4VN/(lhXR#z6QL');
define('NONCE_KEY',        '.V+R ZJ$6-RG5|wqlv)HOTI_Rdj<aDUz(q])*N{#7VBk/U&`o|,_q;^$mN0o!m-j');
define('AUTH_SALT',        'V>o&_Y+dI2ayQ!P`(<0iL IS@Aw%bqkwhQ!wBXc;_`KYR_Oxu@x!3wwr6U>BtQ-?');
define('SECURE_AUTH_SALT', 'd6::Ym$|XSy?$-X~HT7K2}Q 4%?U Xe+gwY2V7FO;kNvnTG5//YZW4:*kfVA5eWw');
define('LOGGED_IN_SALT',   '-ONc3g-<Pm@M-&6;R2JIDX=/~`1 GXaDewKRlAmr2.->k0=hV1!xr8l=j![XDl28');
define('NONCE_SALT',       'vi/{40CMK^mNYWMYv<>+Gi)>g.!S=!h@^^)]*.|Gf>5=JN7(lI1~cB-@>88,mhB1');

$table_prefix  = 'wptests_';   // Only numbers, letters, and underscores please!

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );

define( 'WP_PHP_BINARY', 'php' );

define( 'WPLANG', '' );
