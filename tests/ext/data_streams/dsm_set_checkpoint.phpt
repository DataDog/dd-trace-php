--TEST--
Data Streams Monitoring: checkpoint contexts carry the pathway hashes system-tests expects
--DESCRIPTION--
The service, env and edge tags are those of system-tests' Test_DsmKafka without a cluster id,
whose expected hashes are 4463699290244539355 for the produce and 3735318893869752335 for the
consume. Process tags are off there, and are part of the hash otherwise.
--ENV--
DD_DATA_STREAMS_ENABLED=1
DD_SERVICE=weblog
DD_ENV=system-tests
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

function context_hash(string $context): string {
    $bytes = base64_decode($context, true);
    return sprintf('%u', unpack('P', substr($bytes, 0, 8))[1]);
}

$topic = 'topic:dsm-system-tests-queue';

$produced = \DDTrace\Internal\dsm_set_checkpoint(['direction:out', $topic, 'type:kafka']);
echo "produce: ", context_hash($produced), "\n";

// Edge tag order does not matter, and the parent comes from the message's context.
$consumed = \DDTrace\Internal\dsm_set_checkpoint(['type:kafka', $topic, 'group:testgroup1', 'direction:in'], $produced);
echo "consume: ", context_hash($consumed), "\n";

// A consume without a context starts a new pathway rather than continuing the request's.
$fresh = \DDTrace\Internal\dsm_set_checkpoint(['direction:in', 'group:testgroup1', $topic, 'type:kafka']);
echo "consume without context differs: ", var_export(context_hash($fresh) !== context_hash($consumed), true), "\n";

// So does one whose context does not decode.
$garbage = \DDTrace\Internal\dsm_set_checkpoint(['direction:in', 'group:testgroup1', $topic, 'type:kafka'], 'not a context');
echo "consume with garbage context: ", var_export(context_hash($garbage) === context_hash($fresh), true), "\n";

// Producing twice links both produces to the last consume, not the second one to the first.
$first = \DDTrace\Internal\dsm_set_checkpoint(['direction:out', 'topic:next', 'type:kafka']);
$second = \DDTrace\Internal\dsm_set_checkpoint(['direction:out', 'topic:next', 'type:kafka']);
echo "consecutive produces share a parent: ", var_export(context_hash($first) === context_hash($second), true), "\n";

?>
--EXPECT--
produce: 4463699290244539355
consume: 3735318893869752335
consume without context differs: true
consume with garbage context: true
consecutive produces share a parent: true
