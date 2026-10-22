--TEST--
Data Streams Monitoring: a reset forgets the request's pathway and those set aside
--DESCRIPTION--
Integrations of long-running SAPIs reset the pathway at the edges of each request they serve, so
one request's checkpoints do not continue another's.
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

$in = ['direction:in', 'topic:orders', 'type:rabbitmq'];
$out = ['direction:out', 'topic:billing', 'type:rabbitmq'];

$message = \DDTrace\Internal\dsm_set_checkpoint($out);
$fresh = context_hash($message);

// One request consumes; the next only produces.
\DDTrace\Internal\dsm_set_checkpoint($in, $message);
\DDTrace\Internal\dsm_reset_pathway();
echo "after a reset, a produce starts a new pathway: ",
    var_export(context_hash(\DDTrace\Internal\dsm_set_checkpoint($out)) === $fresh, true), "\n";

\DDTrace\Internal\dsm_set_checkpoint($in, $message);
\DDTrace\Internal\dsm_push_pathway();
\DDTrace\Internal\dsm_reset_pathway();
\DDTrace\Internal\dsm_pop_pathway();
echo "a reset drops the pathways set aside: ",
    var_export(context_hash(\DDTrace\Internal\dsm_set_checkpoint($out)) === $fresh, true), "\n";

?>
--EXPECT--
after a reset, a produce starts a new pathway: true
a reset drops the pathways set aside: true
