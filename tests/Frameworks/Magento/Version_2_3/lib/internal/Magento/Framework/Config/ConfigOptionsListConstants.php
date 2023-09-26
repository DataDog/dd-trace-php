<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Framework\Config;

/**
 * Deployment configuration options constant storage
 * @api
 * @since 100.0.2
 */
class ConfigOptionsListConstants
{
    /**#@+
     * Path to the values in the deployment config
     */
    const CONFIG_PATH_INSTALL_DATE = 'install/date';
    const CONFIG_PATH_CRYPT_KEY = 'crypt/key';
    const CONFIG_PATH_SESSION_SAVE = 'session/save';
    const CONFIG_PATH_RESOURCE_DEFAULT_SETUP = 'resource/default_setup/connection';
    const CONFIG_PATH_DB_CONNECTION_DEFAULT_DRIVER_OPTIONS = 'db/connection/default/driver_options';
    const CONFIG_PATH_DB_CONNECTION_DEFAULT = 'db/connection/default';
    const CONFIG_PATH_DB_CONNECTIONS = 'db/connection';
    const CONFIG_PATH_DB_PREFIX = 'db/table_prefix';
    const CONFIG_PATH_X_FRAME_OPT = 'x-frame-options';
    const CONFIG_PATH_CACHE_HOSTS = 'http_cache_hosts';
    const CONFIG_PATH_BACKEND = 'backend';
    const CONFIG_PATH_INSTALL = 'install';
    const CONFIG_PATH_CRYPT = 'crypt';
    const CONFIG_PATH_SESSION = 'session';
    const CONFIG_PATH_DB = 'db';
    const CONFIG_PATH_RESOURCE = 'resource';
    const CONFIG_PATH_CACHE_TYPES = 'cache_types';
    const CONFIG_PATH_DOCUMENT_ROOT_IS_PUB = 'directories/document_root_is_pub';
    const CONFIG_PATH_DB_LOGGER_OUTPUT = 'db_logger/output';
    const CONFIG_PATH_DB_LOGGER_LOG_EVERYTHING = 'db_logger/log_everything';
    const CONFIG_PATH_DB_LOGGER_QUERY_TIME_THRESHOLD = 'db_logger/query_time_threshold';
    const CONFIG_PATH_DB_LOGGER_INCLUDE_STACKTRACE = 'db_logger/include_stacktrace';
    /**#@-*/

    /**
     * Parameter for disabling/enabling static content deployment on demand in production mode
     * Can contains 0/1 value
     */
    const CONFIG_PATH_SCD_ON_DEMAND_IN_PRODUCTION = 'static_content_on_demand_in_production';

    /**
     * Parameter for forcing HTML minification even if file is already minified.
     */
    const CONFIG_PATH_FORCE_HTML_MINIFICATION = 'force_html_minification';

    /**#@+
     * Input keys for the options
     */
    const INPUT_KEY_ENCRYPTION_KEY = 'key';
    const INPUT_KEY_SESSION_SAVE = 'session-save';
    const INPUT_KEY_DB_HOST = 'db-host';
    const INPUT_KEY_DB_NAME = 'db-name';
    const INPUT_KEY_DB_USER = 'db-user';
    const INPUT_KEY_DB_PASSWORD = 'db-password';
    const INPUT_KEY_DB_PREFIX = 'db-prefix';
    const INPUT_KEY_DB_MODEL = 'db-model';
    const INPUT_KEY_DB_INIT_STATEMENTS = 'db-init-statements';
    const INPUT_KEY_DB_ENGINE = 'db-engine';
    const INPUT_KEY_DB_SSL_KEY = 'db-ssl-key';
    const INPUT_KEY_DB_SSL_CERT = 'db-ssl-cert';
    const INPUT_KEY_DB_SSL_CA = 'db-ssl-ca';
    const INPUT_KEY_DB_SSL_VERIFY = 'db-ssl-verify';
    const INPUT_KEY_RESOURCE = 'resource';
    const INPUT_KEY_SKIP_DB_VALIDATION = 'skip-db-validation';
    const INPUT_KEY_CACHE_HOSTS = 'http-cache-hosts';
    /**#@-*/

    /**#@+
     * Input keys for cache configuration
     */
    const KEY_CACHE_FRONTEND = 'cache/frontend';
    const CONFIG_PATH_BACKEND_OPTIONS = 'backend_options';

    /**
     * @deprecated
     *
     * Definition format constant.
     */
    const INPUT_KEY_DEFINITION_FORMAT = 'definition-format';

    /**#@+
     * Values for session-save
     */
    const SESSION_SAVE_FILES = 'files';
    const SESSION_SAVE_DB = 'db';
    const SESSION_SAVE_REDIS = 'redis';
    /**#@-*/

    /**
     * Array Key for session save method
     */
    const KEY_SAVE = 'save';

    /**#@+
     * Array keys for Database configuration
     */
    const KEY_HOST = 'host';
    const KEY_PORT = 'port';
    const KEY_NAME = 'dbname';
    const KEY_USER = 'username';
    const KEY_PASSWORD = 'password';
    const KEY_ENGINE = 'engine';
    const KEY_PREFIX = 'table_prefix';
    const KEY_MODEL = 'model';
    const KEY_INIT_STATEMENTS = 'initStatements';
    const KEY_ACTIVE = 'active';
    const KEY_DRIVER_OPTIONS = 'driver_options';
    /**#@-*/

    /**#@+
     * Array keys for database driver options configurations
     */
    const KEY_MYSQL_SSL_KEY = \PDO::MYSQL_ATTR_SSL_KEY;
    const KEY_MYSQL_SSL_CERT = \PDO::MYSQL_ATTR_SSL_CERT;
    const KEY_MYSQL_SSL_CA = \PDO::MYSQL_ATTR_SSL_CA;

    /**
     * Constant \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT cannot be used as it was introduced in PHP 7.1.4
     * and Magento 2 is currently supporting PHP 7.1.3.
     */
    const KEY_MYSQL_SSL_VERIFY = 1014;
    /**#@-*/

    /**
     * Db config key
     */
    const KEY_DB = 'db';

    /**
     * Array Key for encryption key in deployment config file
     */
    const KEY_ENCRYPTION_KEY = 'key';

    /**
     * Resource config key
     */
    const KEY_RESOURCE = 'resource';

    /**
     * Key for modules
     */
    const KEY_MODULES = 'modules';

    /**
     * Size of random string generated for store's encryption key
     * phpcs:disable
     */
    const STORE_KEY_RANDOM_STRING_SIZE = SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_KEYBYTES;
    //phpcs:enable
}
