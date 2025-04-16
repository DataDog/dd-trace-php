<?php

include_once 'startup_logging.inc';
if (!dd_get_php_cgi()) die('skip: php-cgi SAPI required');
if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There is no background sender on Windows');
if (version_compare(PHP_VERSION, '8.4.0', '<')) die('skip: This tests is only for PHP 8.4');
