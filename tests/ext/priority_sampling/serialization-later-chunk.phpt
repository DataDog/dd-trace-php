--TEST--
Serialization reevaluates sampling for later chunks of the same root
--DESCRIPTION--
This documents existing behavior rather than a spec requirement. The automatic
(non-manual) sampling decision is not locked: each serialized chunk of a root
reevaluates it, so a later chunk may carry a different priority than an earlier
one. This appears deliberate, part of the extended sampling design (5f7f4a4d1)
where the decision is recomputed as spans close and rules start matching.

It departs from the Priority Sampling RFC, which requires the priority to be
locked once any span finishes or the trace propagates, and from the other
tracers (Python, Java, Go, Node.js, .NET, Ruby), none of which rerun the
automatic sampler for later chunks of a trace. Ruby does reconsider rule
sampling on resource changes, but stops once the first chunk is flushed.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_AUTOFINISH_SPANS=0
DD_TRACE_SAMPLE_RATE=0
DD_TRACE_STATS_COMPUTATION_ENABLED=0
--FILE--
<?php

$root = DDTrace\start_span();
$root->name = 'root';

// Flush a completed attached stack while the root stack has no closed spans.
DDTrace\create_stack();
DDTrace\start_span()->name = 'first chunk';
DDTrace\close_span();
DDTrace\flush();
var_dump($root->samplingPriority);

ini_set('datadog.trace.sample_rate', '1');
DDTrace\switch_stack($root);
DDTrace\create_stack();
DDTrace\start_span()->name = 'second chunk';
DDTrace\close_span();
DDTrace\flush();
var_dump($root->samplingPriority);

// The root can be emitted before an orphan stack; neither decision is permanent.
DDTrace\switch_stack($root);
$orphan = DDTrace\create_stack();
DDTrace\start_span()->name = 'orphan';
DDTrace\switch_stack($root);
DDTrace\close_span();
DDTrace\flush();

ini_set('datadog.trace.sample_rate', '0');
DDTrace\switch_stack($orphan);
DDTrace\close_span();
DDTrace\flush();
var_dump($root->samplingPriority);

?>
--EXPECT--
int(-1)
int(2)
int(-1)
