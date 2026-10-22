--TEST--
Data Streams Monitoring: a pathway pushed with $keepCurrent moves into the message's scope
--DESCRIPTION--
A framework's worker fetches a message and then runs its handler. Pushing the pathway with
$keepCurrent before the handler keeps the message's pathway for the handler, without expiring,
and leaves what runs after the handler on a fresh pathway.
--ENV--
DD_DATA_STREAMS_ENABLED=1
DD_DATA_STREAMS_PATHWAY_MAX_AGE_SECONDS=0.5
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
\DDTrace\Internal\dsm_push_pathway();
\DDTrace\Internal\dsm_set_checkpoint($in, $message);
$linked = context_hash(\DDTrace\Internal\dsm_set_checkpoint($out));
\DDTrace\Internal\dsm_pop_pathway();

// The worker fetches the message, then runs its handler.
\DDTrace\Internal\dsm_set_checkpoint($in, $message);
\DDTrace\Internal\dsm_push_pathway(true);
usleep(700000);
echo "the handler continues the fetched message's pathway, however long it runs: ",
    var_export(context_hash(\DDTrace\Internal\dsm_set_checkpoint($out)) === $linked, true), "\n";
\DDTrace\Internal\dsm_pop_pathway();

echo "after the handler, a produce starts a new pathway: ",
    var_export(context_hash(\DDTrace\Internal\dsm_set_checkpoint($out)) === $fresh, true), "\n";

?>
--EXPECT--
the handler continues the fetched message's pathway, however long it runs: true
after the handler, a produce starts a new pathway: true
