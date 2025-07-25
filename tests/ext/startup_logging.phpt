--TEST--
Startup logging is enabled by default
--SKIPIF--
<?php include 'startup_logging_skipif.inc'; ?>
--FILE--
<?php
include_once 'startup_logging.inc';
$logs = dd_get_startup_logs(['-ddatadog.trace.sources_path='], ['DD_TRACE_DEBUG' => 1]);

// Ignore any Agent connection errors for now
unset($logs['agent_error']);
// Ignore sidecar config as it depends on specific versions of PHP for now
unset($logs['sidecar_trace_sender']);

dd_dump_startup_logs($logs);
?>
--EXPECTF--
datadog.trace.sources_path_reachable: false
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
sample_rate: -1
sampling_rules: []
tags: []
service_mapping: []
distributed_tracing_enabled: true
dd_version: null
architecture: "%s"
instrumentation_telemetry_enabled: true
sapi: "cgi-fcgi"
datadog.trace.sources_path: null
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
enabled_from_env: true
opcache.file_cache: null
loaded_by_ssi: false
