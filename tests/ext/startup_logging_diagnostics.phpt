--TEST--
Startup logging diagnostics
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: run-tests crashes with shell commands on PHP 5'); ?>
<?php include 'startup_logging_skipif.inc'; ?>
--FILE--
<?php
include_once 'startup_logging.inc';
$args = [
    '-dopen_basedir=' . __DIR__ . '/sandbox',
    '-dddtrace.request_init_hook=' . __DIR__ . '/includes/request_init_hook.inc',
];
$env = [
    'DD_TRACE_DEBUG=1',
    'DD_AGENT_HOST=invalid_host',
    'DD_SERVICE_NAME=foo_service',
    'DD_TRACE_GLOBAL_TAGS=foo_tag',
    'DD_TRACE_RESOURCE_URI_MAPPING=/foo',
];
$logs = dd_get_startup_logs($args, $env);

dd_dump_startup_logs($logs, [
    'agent_error',
    'open_basedir_init_hook_allowed',
    'open_basedir_container_tagging_allowed',
    'DD_SERVICE_NAME',
    'DD_TRACE_GLOBAL_TAGS',
    'DD_TRACE_RESOURCE_URI_MAPPING',
    'agent_url',
    'ddtrace.request_init_hook',
    'open_basedir_configured',
]);
?>
--EXPECTF--
agent_error: "%s"
open_basedir_init_hook_allowed: false
open_basedir_container_tagging_allowed: false
DD_SERVICE_NAME: "'DD_SERVICE_NAME=foo_service' is deprecated, use DD_SERVICE instead."
DD_TRACE_GLOBAL_TAGS: "'DD_TRACE_GLOBAL_TAGS=foo_tag' is deprecated, use DD_TAGS instead."
DD_TRACE_RESOURCE_URI_MAPPING: "'DD_TRACE_RESOURCE_URI_MAPPING=/foo' is deprecated, use DD_TRACE_RESOURCE_URI_MAPPING_INCOMING and DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING instead."
agent_url: "http://invalid_host:8126"
ddtrace.request_init_hook: "%s/includes/request_init_hook.inc"
open_basedir_configured: true
