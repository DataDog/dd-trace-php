<?php

require __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/chaos.php';
$chaos = new Chaos($allowFatalAndUncaught = true);
set_error_handler([$chaos, 'handleError']);
set_exception_handler([$chaos, 'handleException']);
$chaos->randomRequestPath();
