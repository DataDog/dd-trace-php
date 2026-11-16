<?php

if (!isset($_GET['integrations']) || !is_array($_GET['integrations'])) {
    http_response_code(400);
    die("specify ?integrations[]=xxx");
}

class Redis
{
    public function __construct()
    {
        echo "Redis constructor called\n";
    }
}

foreach ($_GET['integrations'] as $int) {
    if ($int === 'exec') {
        system('true');
    } elseif ($int === 'redis') {
        new Redis();
    } elseif ($int === 'fs') {
        // Triggers the filesystem integration via deferred-loaded fopen() hook.
        $fp = fopen('/dev/null', 'r');
        if ($fp) { fclose($fp); }
    } else {
        http_response_code(400);
    }
}
