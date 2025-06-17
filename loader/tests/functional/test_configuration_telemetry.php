<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$telemetryLogPath = tempnam(sys_get_temp_dir(), 'test_loader_');

$output = runCLI('-r "echo \'foo\';"', true, [
    'DD_TRACE_AGENT_URL=file://'.$telemetryLogPath,
    'DD_TRACE_LOG_LEVEL=debug,startup',
    'DD_INJECTION_FORCE=true',
    'DD_INJECTION_ENABLED=tracer', // Normally set by the injector
]);

assertMatchesFormat($output, <<<EOS
%A
[ddtrace] [startup] DATADOG TRACER CONFIGURATION - %s,"loaded_by_ssi":true%s
%A
EOS
);

// Let time to write the telemetry log
usleep(10000);

$content = file_get_contents($telemetryLogPath);
assertContains($content, '{"name":"instrumentation_source","value":"ssi","origin":"default","config_id":null}');
assertContains($content, '{"name":"ssi_injection_enabled","value":"tracer","origin":"env_var","config_id":null}');
assertContains($content, '{"name":"ssi_forced_injection_enabled","value":"true","origin":"env_var","config_id":null}');
