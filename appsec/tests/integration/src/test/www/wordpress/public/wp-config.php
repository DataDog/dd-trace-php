<?php
define('DB_NAME', 'wordpress');
define('DB_USER', 'root');
define('DB_PASSWORD', 'test');
define('DB_HOST', 'mysql');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

define('DISABLE_WP_CRON', true);

define('AUTH_KEY',         'test-key-1');
define('SECURE_AUTH_KEY',  'test-key-2');
define('LOGGED_IN_KEY',    'test-key-3');
define('NONCE_KEY',        'test-key-4');
define('AUTH_SALT',        'test-salt-1');
define('SECURE_AUTH_SALT', 'test-salt-2');
define('LOGGED_IN_SALT',   'test-salt-3');
define('NONCE_SALT',       'test-salt-4');

$table_prefix = 'wp_';

define('WP_DEBUG', false);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
