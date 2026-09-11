<?php

function fail($message)
{
    fwrite(STDERR, "FAIL [workload]: {$message}\n");
    exit(1);
}

if (!extension_loaded('ddtrace')) {
    fail('ddtrace was not injected by the SSI loader');
}

if (!filter_var(ini_get('datadog.profiling.enabled'), FILTER_VALIDATE_BOOLEAN)) {
    fail('profiling is not enabled after SSI injection');
}

function ssi_profile_leaf($deadline)
{
    $value = 1;
    while (microtime(true) < $deadline) {
        // Keep the VM executing PHP opcodes so the profiler has many opportunities
        // to capture the complete, distinctive stack used by this test.
        for ($i = 1; $i <= 10000; ++$i) {
            $value = (($value * 33) ^ $i) & 0x7fffffff;
        }
    }
    return $value;
}

function ssi_profile_middle($deadline)
{
    return ssi_profile_leaf($deadline);
}

function ssi_profile_root($duration)
{
    return ssi_profile_middle(microtime(true) + $duration);
}

$duration = getenv('SSI_PROFILE_DURATION_SECONDS');
$duration = $duration === false ? 3.0 : (float) $duration;
if ($duration <= 0) {
    fail('SSI_PROFILE_DURATION_SECONDS must be positive');
}

$result = ssi_profile_root($duration);
echo 'SSI profile workload completed; php=', PHP_MAJOR_VERSION, '.', PHP_MINOR_VERSION,
    '; result=', $result, "\n";
