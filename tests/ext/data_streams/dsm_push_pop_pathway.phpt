--TEST--
Data Streams Monitoring: a pushed pathway is set aside until it is popped
--DESCRIPTION--
Integrations that hand each message to a callback push a pathway before the callback and pop it
after, so the callback's checkpoints neither continue the previous message's pathway nor outlive
the callback.
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

// A produce with nothing before it starts a pathway.
$fresh = context_hash(\DDTrace\Internal\dsm_set_checkpoint($out));

// Something consumed outside any callback becomes the request's pathway.
$outer = \DDTrace\Internal\dsm_set_checkpoint($in, \DDTrace\Internal\dsm_set_checkpoint($out));
$afterOuter = context_hash(\DDTrace\Internal\dsm_set_checkpoint($out));

\DDTrace\Internal\dsm_push_pathway();
echo "a pushed pathway starts fresh: ", var_export(context_hash(\DDTrace\Internal\dsm_set_checkpoint($out)) === $fresh, true), "\n";
\DDTrace\Internal\dsm_pop_pathway();

// The callback's message comes from another queue, so its produce has another parent.
\DDTrace\Internal\dsm_push_pathway();
$message = \DDTrace\Internal\dsm_set_checkpoint(['direction:in', 'topic:payments', 'type:rabbitmq'], \DDTrace\Internal\dsm_set_checkpoint($out));
$inCallback = context_hash(\DDTrace\Internal\dsm_set_checkpoint($out));
\DDTrace\Internal\dsm_pop_pathway();
echo "a produce in the callback links to its message: ", var_export($inCallback !== $afterOuter && $inCallback !== $fresh, true), "\n";

// The produce after the pop is still in the outer run of produces, so it has the same parent as
// the one before the push.
echo "popping restores the outer pathway: ", var_export(context_hash(\DDTrace\Internal\dsm_set_checkpoint($out)) === $afterOuter, true), "\n";

// Popping with nothing pushed leaves a fresh pathway.
\DDTrace\Internal\dsm_pop_pathway();
echo "an unmatched pop starts fresh: ", var_export(context_hash(\DDTrace\Internal\dsm_set_checkpoint($out)) === $fresh, true), "\n";

?>
--EXPECT--
a pushed pathway starts fresh: true
a produce in the callback links to its message: true
popping restores the outer pathway: true
an unmatched pop starts fresh: true
