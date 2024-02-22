--TEST--
Do not fail when PHP code couldn't be loaded
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_AUTOLOAD_NO_COMPILE=1
--INI--
datadog.trace.sources_path="{PWD}/does-not-exist"
--FILE--
<?php

class_exists('DDTrace\Invalid');

echo "Request start" . PHP_EOL;

?>
--EXPECTF--
[ddtrace] [warning] Error opening autoloaded file %sdoes-not-exist/bridge/_files_tracer.php
Request start
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
