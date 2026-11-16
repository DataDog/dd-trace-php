--TEST--
DogStatsD client crash reproduction with unreachable Unix socket
--DESCRIPTION--
This test reproduces the crash that happens during request shutdown when:
1. Health metrics are enabled (creates DogStatsD client in rinit)
2. Unix socket path is unreachable (connection fails)
3. Constructor fails but leaves client in corrupted state
4. Request ends normally and destructor crashes accessing corrupted memory
--ENV--
DD_TRACE_HEALTH_METRICS_ENABLED=1
DD_TRACE_ENABLED=1
DD_DOGSTATSD_URL=unix:///nonexistent/path/to/socket
--FILE--
<?php
echo "done", PHP_EOL;
?>
--EXPECT--
done
