--TEST--
Startup logging from JSON fetched at runtime
--INI--
datadog.trace.request_init_hook=
--FILE--
<?php
include_once 'startup_logging.inc';
$logs = json_decode(\DDTrace\startup_logs(), true);

// Ignore any Agent connection errors for now
unset($logs['agent_error']);

dd_dump_startup_logs($logs);
?>
--EXPECTF--
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
debug: false
analytics_enabled: false
sample_rate: 1
sampling_rules: []
tags: []
service_mapping: []
distributed_tracing_enabled: true
priority_sampling_enabled: true
dd_version: null
architecture: "%s"
sapi: "cli"
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
datadog.trace.request_init_hook_reachable: false
