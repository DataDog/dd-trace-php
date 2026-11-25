--TEST--
Agentless endpoints fall back to datadoghq.com when DD_SITE is invalid
--ENV--
DD_TRACE_AGENTLESS=1
DD_API_KEY=1234
DD_SITE=bad/site
--INI--
datadog.trace.log_file={PWD}/sidecar_agentless_invalid_site.log
datadog.trace.generate_root_span=0
--FILE--
<?php
$file = __DIR__ . '/sidecar_agentless_invalid_site.log';
clearstatcache(true, $file);
// The sidecar copies its logs for every session into each connected log file (at trace level in
// CI), so this file can grow faster than we read it. Our line is logged before the sidecar
// connects, so only scan what was there when the script started.
$found = false;
if ($fp = @fopen($file, 'r')) {
    $remaining = filesize($file);
    while (!$found && $remaining > 0 && ($line = fgets($fp)) !== false) {
        $remaining -= strlen($line);
        $found = strpos($line, 'Invalid DD_SITE bad/site, falling back to datadoghq.com') !== false;
    }
    fclose($fp);
}
var_dump($found);
?>
--EXPECT--
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/sidecar_agentless_invalid_site.log'); ?>
