--TEST--
Data Streams Monitoring: without DD_SERVICE, the hash covers the process tags and svc.auto
--DESCRIPTION--
The sidecar reports the process tags followed by svc.auto:<default service name> in the DSM
payload when the user did not set the service, so that is what the pathway hash covers. In the
CLI the default service name is the script's name.
--ENV--
DD_DATA_STREAMS_ENABLED=1
DD_ENV=dsm-test
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=1
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
require __DIR__ . '/includes/expected_hash.inc';

$edgeTags = ['direction:in', 'group:billing', 'topic:orders', 'type:kafka'];
$context = \DDTrace\Internal\dsm_set_checkpoint($edgeTags);
$processTags = dsm_process_tags();
$service = basename($_SERVER['argv'][0]);
echo "service-source tag is svc.auto:<script>: ", var_export(end($processTags) === "svc.auto:$service", true), "\n";

$expected = dsm_expected_hash($service, 'dsm-test', $edgeTags, $processTags);
echo "matches with svc.auto: ", var_export(dsm_context_hash($context) === $expected, true), "\n";
$withoutSource = dsm_expected_hash($service, 'dsm-test', $edgeTags, array_slice($processTags, 0, -1));
echo "differs without it: ", var_export(dsm_context_hash($context) !== $withoutSource, true), "\n";
?>
--EXPECT--
service-source tag is svc.auto:<script>: true
matches with svc.auto: true
differs without it: true
