--TEST--
Downstream sampler: the counter is seeded lazily, in each worker
--DESCRIPTION--
The getter reports "not seeded yet" in the parent without drawing a seed. Two
children forked from it each take one decision and so draw their own seed:
their counters differ (pre-forked FPM workers must not run in lockstep).
--SKIPIF--
<?php
if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) die('skip pcntl/posix required');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,should_send_downstream_bodies,downstream_sampler_get_counter};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
]);

var_dump(rinit());
var_dump(downstream_sampler_get_counter());

$files = [];
$pids = [];
for ($i = 0; $i < 2; $i++) {
    $f = tempnam(sys_get_temp_dir(), 'seed');
    $pid = pcntl_fork();
    if ($pid === 0) {
        should_send_downstream_bodies();
        file_put_contents($f, (string)downstream_sampler_get_counter());
        // no shutdown: the helper connection belongs to the parent
        posix_kill(posix_getpid(), SIGKILL);
    }
    $files[] = $f;
    $pids[] = $pid;
}
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $st);
}
$c = array_map('file_get_contents', $files);
array_map('unlink', $files);
var_dump($c[0] !== '' && $c[1] !== '');
var_dump($c[0] !== $c[1]);
var_dump(downstream_sampler_get_counter());
?>
--EXPECT--
bool(true)
NULL
bool(true)
bool(true)
NULL
