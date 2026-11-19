--TEST--
Two PHP workers share hook-only exposure decisions for allocation, variant and serial changes
--SKIPIF--
<?php if (!extension_loaded('pcntl') || !function_exists('stream_socket_pair')) die('skip: fork and socket pairs required'); ?>
--ENV--
DD_TRACE_CLI_ENABLED=1
DD_TRACE_SIDECAR_CONNECTION_MODE=subprocess
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=1
DD_FEATURE_FLAGS_EXPOSURES_DATADOG_LOGGING_ENABLED=false
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_SERVICE=php-hook-only-workers
--FILE--
<?php
require_once __DIR__ . '/stubs/exposure_sidecar.inc';
function observe($key, $assignment) {
    ffeAwaitExposureSidecar();
    return DDTrace\Internal\record_ffe_exposure('flag', $key, [], $assignment[0], $assignment[1], $assignment[2]);
}
function receive($pipe) {
    $line = fgets($pipe);
    if ($line === false) throw new RuntimeException('worker disconnected or timed out');
    return trim($line);
}
$a = ['alloc', 'on', null];
$changes = ['allocation' => ['other', 'on', null], 'variant' => ['alloc', 'off', null], 'serial' => ['alloc', 'on', 0]];
$prefix = uniqid('admission-', true);
list($parent, $child) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
stream_set_timeout($parent, 5);
stream_set_timeout($child, 5);
$pid = pcntl_fork();
if ($pid === -1) throw new RuntimeException('fork failed');
if ($pid === 0) {
    fclose($parent);
    fwrite($child, getmypid() . "\n");
    while (($line = fgets($child)) !== false) {
        list($key, $assignment) = json_decode($line, true);
        fwrite($child, observe($key, $assignment) . "\n");
    }
    exit(DDTrace\Testing\flush_ffe_exposures() ? 1 : 0);
}
fclose($child);
if ((int) receive($parent) !== $pid || $pid === getmypid()) throw new RuntimeException('worker identity mismatch');
foreach ($changes as $field => $b) {
    $key = $prefix . $field;
    $statuses = [observe($key, $a)];
    fwrite($parent, json_encode([$key, $b]) . "\n");
    $statuses[] = receive($parent);
    $statuses[] = observe($key, $a);
    fwrite($parent, json_encode([$key, $a]) . "\n");
    $statuses[] = receive($parent);
    echo $field, ': ', implode(',', $statuses), "\n";
}
$key = $prefix . 'race';
fwrite($parent, json_encode([$key, $a]) . "\n");
$race = [observe($key, $a), receive($parent)];
sort($race);
echo 'race: ', implode(',', $race), "\n";
fclose($parent);
pcntl_waitpid($pid, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status)) throw new RuntimeException('child handoff');
var_dump(DDTrace\Testing\flush_ffe_exposures());
?>
--EXPECT--
allocation: admitted,admitted,admitted,repeat
variant: admitted,admitted,admitted,repeat
serial: admitted,admitted,admitted,repeat
race: admitted,repeat
bool(false)
