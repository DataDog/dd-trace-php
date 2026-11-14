<?php
// Child script for php2shell_mitigation_deferral_not_carried_over.phpt
// (PHP 8.4+), run by php-cgi -T 2 from a copy in a temporary directory, which
// also holds a marker file and a .user.ini.
// Request 1 (no marker yet): writes .user.ini with memory_limit=-1 for the
// next request, then has request_parse_body() parse the large PUT body, which
// ends in the deferred fatal. Request 2: memory_limit=-1, so no window arms.
$marker = __DIR__ . '/marker';
if (!file_exists($marker)) {
    touch($marker);
    file_put_contents(__DIR__ . '/.user.ini', "memory_limit=-1\n");
    // php-cgi re-reads .user.ini only in a later second (user_ini.cache_ttl=0).
    sleep(2);
    echo "REQ1 memory_limit=", ini_get('memory_limit'), "\n";
    [$post, $files] = request_parse_body();
    echo "REQ1 parse returned\n";
} else {
    echo "REQ2 RAN memory_limit=", ini_get('memory_limit'), "\n";
}
