--TEST--
Data Streams Monitoring: outside a message's scope, a produce does not continue an old pathway
--DESCRIPTION--
Nothing says when a request outside a message's scope has finished with a message it consumed, so
a produce only continues the request's pathway if its last checkpoint is at most
DD_DATA_STREAMS_PATHWAY_MAX_AGE_SECONDS old. Within a message's scope, the pathway lasts as long as
the scope.
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

// A produce with nothing before it starts a pathway; its context stands in for a message's.
$message = \DDTrace\Internal\dsm_set_checkpoint($out);
$fresh = context_hash($message);

\DDTrace\Internal\dsm_set_checkpoint($in, $message);
$linked = context_hash(\DDTrace\Internal\dsm_set_checkpoint($out));
echo "a produce right after a consume continues its pathway: ", var_export($linked !== $fresh, true), "\n";

usleep(700000);
echo "once the pathway is older than the maximum age, a produce starts a new one: ",
    var_export(context_hash(\DDTrace\Internal\dsm_set_checkpoint($out)) === $fresh, true), "\n";

\DDTrace\Internal\dsm_push_pathway();
\DDTrace\Internal\dsm_set_checkpoint($in, $message);
usleep(700000);
echo "within a message's scope, the pathway does not expire: ",
    var_export(context_hash(\DDTrace\Internal\dsm_set_checkpoint($out)) === $linked, true), "\n";
\DDTrace\Internal\dsm_pop_pathway();

ini_set('datadog.data_streams_pathway_max_age_seconds', '0');
\DDTrace\Internal\dsm_set_checkpoint($in, $message);
usleep(700000);
echo "with no maximum age, the pathway does not expire: ",
    var_export(context_hash(\DDTrace\Internal\dsm_set_checkpoint($out)) === $linked, true), "\n";

?>
--EXPECT--
a produce right after a consume continues its pathway: true
once the pathway is older than the maximum age, a produce starts a new one: true
within a message's scope, the pathway does not expire: true
with no maximum age, the pathway does not expire: true
