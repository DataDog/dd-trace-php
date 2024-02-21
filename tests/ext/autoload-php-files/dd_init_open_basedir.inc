<?php

$dir = dirname(__DIR__) . '/includes';
error_log('Calling dd_init.php from parent directory "' . $dir . '"');
ddtrace_init($dir);
