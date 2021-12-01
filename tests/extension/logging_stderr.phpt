--TEST--
Logging to stderr
--INI--
error_reporting=2147483647
ddappsec.log_file=stderr
--FILE--
<?php
use function datadog\appsec\testing\{mlog,fdclose};
use const datadog\appsec\testing\log_level\WARNING;

var_dump(fdclose(2));
$f = tmpfile(); // takes over fd 2
mlog(WARNING, "warning message");

fseek($f, 0);
echo "Contents:\n";
echo stream_get_contents($f);

fclose($f);
mlog(WARNING, "should not be printable");
?>
--EXPECTF--
bool(true)
Contents:
%s[warning] warning message at %s

Warning: datadog\appsec\testing\mlog(): [ddappsec] Failed writing to log file (errno 9: Bad file descriptor) in %s on line %d
