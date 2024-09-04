--TEST--
Send crashtracker report when segmentation fault signal is raised and config enables it
--SKIPIF--
<?php
if (!extension_loaded('posix')) die('skip: posix extension required');
if (getenv('SKIP_ASAN') || getenv('USE_ZEND_ALLOC') === '0') die("skip: intentionally causes segfaults");
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support %A in EXPECTF");
if (getenv('DD_TRACE_CLI_ENABLED') === '0') die("skip: tracer is disabled");
if (PHP_VERSION_ID < 70200) die("skip: TEST_PHP_EXTRA_ARGS is only available on PHP 7.2+");
?>
--ENV--
DD_TRACE_SIDECAR_TRACE_SENDER=1
--INI--
datadog.trace.agent_url="file://{PWD}/crashtracker_segfault_agent.out"
--FILE--
<?php

usleep(100000); // Let time to the sidecar to open the crashtracker socket

$php = getenv('TEST_PHP_EXECUTABLE');
$args = getenv('TEST_PHP_ARGS')." ".getenv("TEST_PHP_EXTRA_ARGS");
$cmd = $php." ".$args." -r 'posix_kill(posix_getpid(), 11);'";
system($cmd);

for ($i = 0; $i < 100; ++$i) {
    $content = file_get_contents(__DIR__."/crashtracker_segfault_agent.out");
    if (false != strpos($content, '"signame": "SIGSEGV"')) {
        echo $content;
        break;
    }
    usleep(5000); // Let time for the crash report to be uploaded
}

?>
--EXPECTF--
%A
  "counters": {
%A
  },
  "files": {
    "/proc/self/maps": [
%A
    ]
  },
  "incomplete": %s,
  "metadata": {
    "library_name": "dd-trace-php",
    "library_version": "%s",
    "family": "php",
    "tags": [
%A
    ]
  },
  "os_info": {
%A
  },
  "proc_info": {
    "pid": %d
  },
  "siginfo": {
    "signum": 11,
    "signame": "SIGSEGV"
  },
  "timestamp": "%s",
  "uuid": "%s"
%A
--CLEAN--
<?php

@unlink(__DIR__ . '/crashtracker_segfault_agent.out');
