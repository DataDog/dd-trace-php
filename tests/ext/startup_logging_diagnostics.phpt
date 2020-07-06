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
    'DD_AGENT_HOST=invalid_host',
    'DD_SERVICE_NAME=foo_service',
    'DD_TRACE_GLOBAL_TAGS=foo_tag',
];
$logs = dd_get_startup_logs($args, $env);

dd_dump_startup_logs($logs, [
    'agent_error',
    'open_basedir_init_hook_allowed',
    'open_basedir_container_tagging_allowed',
    'service_name',
    'service_name_error',
    'global_tags',
    'global_tags_error',
    'agent_url',
    'ddtrace.request_init_hook',
    'open_basedir_configured',
]);
?>
--EXPECTF--
agent_error: "Could not resolve host: invalid_host"
open_basedir_init_hook_allowed: false
open_basedir_container_tagging_allowed: false
service_name: "foo_service"
service_name_error: "Usage of DD_SERVICE_NAME is deprecated, use DD_SERVICE instead."
global_tags: "foo_tag"
global_tags_error: "Usage of DD_TRACE_GLOBAL_TAGS is deprecated, use DD_TAGS instead."
agent_url: "http://invalid_host:8126"
ddtrace.request_init_hook: "%s/includes/request_init_hook.inc"
open_basedir_configured: true
