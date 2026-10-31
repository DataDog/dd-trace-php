<?php
// Child script for php2shell_mitigation_deferred_warning_limit.phpt (PHP 8.4+).
// An output buffer callback (chunk size 1, so it runs on every write) watches
// for the deferred-fatal warning, then allocates P2S_ALLOC_MIB MiB (if not 0).
// It records its progress in the file P2S_LOG.
$GLOBALS['p2s_log'] = getenv('P2S_LOG');
$GLOBALS['p2s_alloc_mib'] = (int)getenv('P2S_ALLOC_MIB');
ob_start(function ($buf) {
    static $done = false;
    if (!$done && strpos($buf, 'PHP2Shell mitigation: request data parse exceeded') !== false) {
        $done = true;
        file_put_contents($GLOBALS['p2s_log'], "callback saw warning\n", FILE_APPEND);
        $mib = $GLOBALS['p2s_alloc_mib'];
        if ($mib > 0) {
            $keep = str_repeat('X', $mib * 1048576);
            file_put_contents($GLOBALS['p2s_log'], "callback allocated $mib MiB\n", FILE_APPEND);
        }
    }
    return $buf;
}, 1);
[$post, $files] = request_parse_body();
echo "SCRIPT RAN\n";
