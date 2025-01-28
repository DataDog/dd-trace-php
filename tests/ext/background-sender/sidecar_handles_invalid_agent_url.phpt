--TEST--
The sidecar properly handles invalid agent urls
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip: valgrind reports sendmsg(msg.msg_control) points to uninitialised byte(s), but it is unproblematic and outside our control in rust code'); ?>
--ENV--
DD_TRACE_AGENT_URL=/invalid
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_CRASHTRACKING_ENABLED=0
--FILE--
<?php

?>
--EXPECTF--
[ddtrace] [error] Invalid DD_TRACE_AGENT_URL: /invalid. A proper agent URL must be unix:///path/to/agent.sock or http://hostname:port/.
