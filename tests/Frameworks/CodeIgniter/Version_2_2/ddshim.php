<?php

// set up ddtrace integration (should be temporary)
require dirname(dirname(ini_get('ddtrace.request_init_hook'))) . '/bridge/dd_init.php';

/* CodeIgniter expects the CWD to be set to the root directory but the builtin
 * CLI SAPI server will not do this:
 * https://www.php.net/manual/en/features.commandline.differences.php */
chdir(__DIR__);

require 'index.php';
