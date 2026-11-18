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
$logs = (string) @file_get_contents($file);
var_dump(strpos($logs, 'Invalid DD_SITE bad/site, falling back to datadoghq.com') !== false);
?>
--EXPECT--
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/sidecar_agentless_invalid_site.log'); ?>
