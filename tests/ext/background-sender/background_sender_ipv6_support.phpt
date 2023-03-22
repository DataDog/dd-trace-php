--TEST--
DD_AGENT_HOST with IPv6 works
--SKIPIF--
<?php include __DIR__ . '/../startup_logging_skipif.inc'; ?>
--ENV--
DD_AGENT_HOST=::1
--FILE--
<?php
include_once __DIR__ . '/../startup_logging.inc';

$logs = dd_get_startup_logs([], ['DD_TRACE_DEBUG=1']);

dd_dump_startup_logs($logs, [
    'agent_url',
]);
?>
--EXPECT--
agent_url: "http://[::1]:8126"
