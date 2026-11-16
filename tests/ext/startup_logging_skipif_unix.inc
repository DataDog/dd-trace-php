<?php

include_once 'startup_logging_skipif.inc';
if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There is no background sender on Windows');
