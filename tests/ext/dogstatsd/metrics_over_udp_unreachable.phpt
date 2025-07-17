--TEST--
DogStatsD client crash reproduction with unreachable UDP socket
--DESCRIPTION--
This test reproduces the crash with unreachable UDP socket during request shutdown
Using 192.0.2.1 (TEST-NET-1) which is guaranteed to be unreachable
The crash happens when:
1. Health metrics enabled -> DogStatsD client created
2. UDP socket creation or connection fails
3. Constructor leaves client in corrupted state
4. Request shutdown -> destructor crashes accessing corrupted memory
--ENV--
DD_TRACE_HEALTH_METRICS_ENABLED=1
DD_TRACE_ENABLED=1
DD_DOGSTATSD_URL=udp://192.0.2.1:8125
--FILE--
<?php
echo "done", PHP_EOL;
?>
--EXPECT--
done
