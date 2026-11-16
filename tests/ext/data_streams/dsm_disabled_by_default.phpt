--TEST--
Data Streams Monitoring: checkpoints are off unless DD_DATA_STREAMS_ENABLED is set
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

var_dump(dd_trace_env_config("DD_DATA_STREAMS_ENABLED"));
var_dump(\DDTrace\Internal\dsm_set_checkpoint(['direction:out', 'topic:orders', 'type:kafka']));

?>
--EXPECT--
bool(false)
NULL
