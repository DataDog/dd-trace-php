--TEST--
Startup logging is enabled by default
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: run-tests crashes with shell commands on PHP 5'); ?>
<?php include 'startup_logging_skipif.inc'; ?>
--FILE--
<?php
include_once 'startup_logging.inc';
$logs = dd_get_startup_logs(['-ddatadog.trace.request_init_hook='], ['DD_TRACE_DEBUG=1']);

// Ignore any Agent connection errors for now
unset($logs['agent_error']);

dd_dump_startup_logs($logs);
?>
--EXPECTF--
datadog.trace.request_init_hook_reachable: false
date: "%s"
os_name: "%s"
os_version: "%s"
version: "%s"
lang: "php"
lang_version: "%s"
env: null
enabled: true
service: null
enabled_cli: %s
agent_url: "%s"
debug: true
analytics_enabled: false
sample_rate: 1.0000
sampling_rules: null
tags: []
service_mapping: []
distributed_tracing_enabled: true
priority_sampling_enabled: true
dd_version: null
architecture: "%s"
sapi: "cgi-fcgi"
datadog.trace.request_init_hook: null
open_basedir_configured: false
uri_fragment_regex: null
uri_mapping_incoming: null
uri_mapping_outgoing: null
auto_flush_enabled: false
generate_root_span: true
http_client_split_by_domain: false
measure_compile_time: true
report_hostname_on_root_span: false
traced_internal_functions: null
auto_prepend_file_configured: false
integrations_disabled: "default"
enabled_from_env: true
opcache.file_cache: null
