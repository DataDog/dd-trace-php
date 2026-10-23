--TEST--
Data Streams Monitoring: DD_DATA_STREAMS_ENABLED starts the sidecar
--DESCRIPTION--
The sidecar aggregates the stats points and sends them, so checkpoints are only set when it is
enabled. Nothing else here enables it: telemetry and the sidecar trace sender are off, and neither
AppSec, the flagging provider nor OTel metrics is on.
--SKIPIF--
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: the sidecar trace sender is always on on Windows'); ?>
--ENV--
DD_DATA_STREAMS_ENABLED=1
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

var_dump(is_string(\DDTrace\Internal\dsm_set_checkpoint(['direction:out', 'topic:orders', 'type:kafka'])));

?>
--EXPECT--
bool(true)
