--TEST--
Startup logging diagnostics
--SKIPIF--
<?php include 'startup_logging_skipif.inc'; ?>
--FILE--
<?php
include_once 'startup_logging.inc';
$args = [
    '-dopen_basedir=' . __DIR__ . '/sandbox',
    '-ddatadog.trace.sources_path=' . __DIR__ . '/includes/',
];
$env = [
    'DD_TRACE_DEBUG' => '1',
    'DD_AGENT_HOST' => 'invalid_host',
    'DD_SERVICE' => 'foo_service',
    'DD_TAGS' => 'foo:tag',
];
$logs = dd_get_startup_logs($args, $env);

dd_dump_startup_logs($logs, [
    'open_basedir_sources_allowed',
    'open_basedir_container_tagging_allowed',
    'service',
    'tags',
    'agent_url',
    'datadog.trace.sources_path',
    'open_basedir_configured',
]);
// Sidecar tracing doesn't support agent_error ... yet.
var_dump(strncasecmp(PHP_OS, "WIN", 3) == 0 || isset($logs["agent_error"]));
?>
--EXPECTF--
open_basedir_sources_allowed: false
open_basedir_container_tagging_allowed: false
service: "foo_service"
agent_url: "http://invalid_host:8126"
tags: {"foo":"tag"}
d%s.sources_path: "%s/includes/"
open_basedir_configured: true
bool(true)
