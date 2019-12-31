<?php

use DDTrace\Tests\PostInstallHook\CliInput;
use DDTrace\Tests\PostInstallHook\Request;

if ('cli' !== PHP_SAPI) {
    die('Cannot run CLI script from web SAPI');
}

require __DIR__ . '/classes.php';

$request = new Request(new CliInput());
return $request->send();
