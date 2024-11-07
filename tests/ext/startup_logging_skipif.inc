<?php

include_once 'startup_logging.inc';
if (!dd_get_php_cgi()) die('skip: php-cgi SAPI required');
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS not available on PHP 7.0 and 7.1');
