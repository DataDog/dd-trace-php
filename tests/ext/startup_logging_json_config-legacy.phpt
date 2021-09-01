--TEST--
Startup logging config from JSON fetched at runtime
--SKIPIF--
<?php if (PHP_VERSION_ID >= 70000) die('skip: Test does not work with internal spans'); ?>
--ENV--
DD_ENV=my-env
DD_SERVICE=my-service
DD_TRACE_CLI_ENABLED=1
DD_TRACE_DEBUG=1
DD_TRACE_SAMPLE_RATE=0.42
DD_TRACE_SAMPLING_RULES=[{"service": "a.*", "name": "b", "sample_rate": 0.1}, {"sample_rate": 0.2}]
DD_TAGS=key1:value1,key2:value2
DD_SERVICE_MAPPING=pdo:payments-db,mysqli:orders-db
DD_DISTRIBUTED_TRACING=0
DD_PRIORITY_SAMPLING=0
DD_VERSION=4.2
DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX=^[a-f0-9]{7}$
DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=cities/*,articles/*
DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=foo/*,bar/*
DD_TRACE_AUTO_FLUSH_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN=1
DD_TRACE_MEASURE_COMPILE_TIME=0
DD_TRACE_REPORT_HOSTNAME=1
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum,mt_rand,DateTime::add
DD_INTEGRATIONS_DISABLED=curl,mysqli
DD_TRACE_ENABLED=0
--INI--
auto_prepend_file={PWD}/includes/sanity_check.php
--FILE--
<?php
include_once 'startup_logging.inc';
$logs = json_decode(\DDTrace\startup_logs(), true);

dd_dump_startup_logs($logs, [
    'env',
    'service',
    'enabled_cli',
    'debug',
    'sample_rate',
    'sampling_rules',
    // TODO Add integration config
    'tags',
    'service_mapping',
    'distributed_tracing_enabled',
    'priority_sampling_enabled',
    'dd_version',
    'uri_fragment_regex',
    'uri_mapping_incoming',
    'uri_mapping_outgoing',
    'auto_flush_enabled',
    'generate_root_span',
    'http_client_split_by_domain',
    'measure_compile_time',
    'report_hostname_on_root_span',
    'traced_internal_functions',
    'auto_prepend_file_configured',
    'integrations_disabled',
    'enabled_from_env',
]);
?>
--EXPECT--
Sanity check
env: "my-env"
service: "my-service"
enabled_cli: true
debug: true
sample_rate: 0.4200
sampling_rules: "[{"service": "a.*", "name": "b", "sample_rate": 0.1}, {"sample_rate": 0.2}]"
tags: "key1:value1,key2:value2"
service_mapping: "pdo:payments-db,mysqli:orders-db"
distributed_tracing_enabled: false
priority_sampling_enabled: false
dd_version: "4.2"
uri_fragment_regex: "^[a-f0-9]{7}$"
uri_mapping_incoming: "cities/*,articles/*"
uri_mapping_outgoing: "foo/*,bar/*"
auto_flush_enabled: true
generate_root_span: false
http_client_split_by_domain: true
measure_compile_time: false
report_hostname_on_root_span: true
traced_internal_functions: "array_sum,mt_rand,DateTime::add"
auto_prepend_file_configured: true
integrations_disabled: "curl,mysqli"
enabled_from_env: false
