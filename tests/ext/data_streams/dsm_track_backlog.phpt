--TEST--
Data Streams Monitoring: backlog offsets are accepted whether or not they can be recorded
--DESCRIPTION--
The sidecar keeps the highest offset per backlog; what reaches the agent is covered by the
libdatadog tests. Here: the function takes any tags and offset, skips non-string tags and
negative offsets, and returns nothing.
--ENV--
DD_DATA_STREAMS_ENABLED=1
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

$produce = ['topic:orders', 'type:kafka_produce', 'partition:0'];
var_dump(\DDTrace\Internal\dsm_track_backlog($produce, 42));
var_dump(\DDTrace\Internal\dsm_track_backlog($produce, -1001));
var_dump(\DDTrace\Internal\dsm_track_backlog(['topic:orders', 7, null, 'type:kafka_commit'], 3));
var_dump(\DDTrace\Internal\dsm_track_backlog([], 0));

?>
--EXPECT--
NULL
NULL
NULL
NULL
