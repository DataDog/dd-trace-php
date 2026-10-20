--TEST--
Data Streams Monitoring: with DD_SERVICE set, the hash covers the process tags and svc.user
--DESCRIPTION--
The sidecar reports the process tags followed by svc.user:true in the DSM payload when the user
set the service, so that is what the pathway hash covers.
--ENV--
DD_DATA_STREAMS_ENABLED=1
DD_SERVICE=orders-service
DD_ENV=dsm-test
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=1
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
require __DIR__ . '/includes/expected_hash.inc';

// The helper reproduces a hash system-tests expects (see dsm_set_checkpoint.phpt).
echo "helper: ", dsm_expected_hash('weblog', 'system-tests', ['direction:out', 'topic:dsm-system-tests-queue', 'type:kafka'], []), "\n";

$edgeTags = ['direction:out', 'topic:orders', 'type:kafka'];
$context = \DDTrace\Internal\dsm_set_checkpoint($edgeTags);
$processTags = dsm_process_tags();
echo "service-source tag: ", end($processTags), "\n";

$expected = dsm_expected_hash('orders-service', 'dsm-test', $edgeTags, $processTags);
echo "matches with svc.user: ", var_export(dsm_context_hash($context) === $expected, true), "\n";
$withoutSource = dsm_expected_hash('orders-service', 'dsm-test', $edgeTags, array_slice($processTags, 0, -1));
echo "differs without it: ", var_export(dsm_context_hash($context) !== $withoutSource, true), "\n";
?>
--EXPECT--
helper: 4463699290244539355
service-source tag: svc.user:true
matches with svc.user: true
differs without it: true
