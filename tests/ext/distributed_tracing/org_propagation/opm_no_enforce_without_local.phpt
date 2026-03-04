--TEST--
Foreign OPM is propagated without enforcement when no local OPM is known
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_SAMPLING_PRIORITY=2
HTTP_X_DATADOG_ORIGIN=some-org
HTTP_X_DATADOG_TAGS=_dd.p.custom=value
HTTP_X_DD_OPM=foreign-opm
--FILE--
<?php

$headers = DDTrace\generate_distributed_tracing_headers(["datadog"]);
// Without local OPM, context is not enforced: origin and tags must be kept
echo "origin kept: " . (($headers['x-datadog-origin'] ?? '') === 'some-org' ? 'yes' : 'no') . "\n";
echo "custom tag kept: " . (strpos($headers['x-datadog-tags'] ?? '', '_dd.p.custom=value') !== false ? 'yes' : 'no') . "\n";
echo "priority kept: " . (($headers['x-datadog-sampling-priority'] ?? '') === '2' ? 'yes' : 'no') . "\n";
echo "opm propagated: " . (($headers['x-dd-opm'] ?? '') === 'foreign-opm' ? 'yes' : 'no') . "\n";

?>
--EXPECT--
origin kept: yes
custom tag kept: yes
priority kept: yes
opm propagated: yes
