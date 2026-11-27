--TEST--
Downstream curl requests: curl_reset() of a handle whose transfer is in flight
--DESCRIPTION--
Exception to G5.2: curl_reset() of a handle in flight (here in a multi
handle, between two curl_multi_exec() calls and from a callback of another
transfer of the same multi; ext/curl refuses it from the handle's own
callbacks, curl_reset_refused.phpt) puts ext/curl's functions back in its
slots. The transfer is observed only up to there: no more calls of ours are
counted for it, the end of the transfer restores nothing (no slot holds ours
anymore), nothing crashes, and the app sees what it sees without the layer
(after the reset the body goes to the output, as RETURNTRANSFER was reset).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the write/header slots');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{curl_downstream_slots,curl_downstream_dump,curl_downstream_installed_count};
require __DIR__ . '/inc/curl_downstream.php';
$base = cd_server_start();

// a second server, so that two transfers run at the same time
function other_server_start()
{
    $cmd = [PHP_BINARY, '-n', __DIR__ . '/inc/curl_server.php'];
    if (PHP_VERSION_ID < 70400) {
        $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', $cmd));
    }
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, null, []);
    $line = fgets($pipes[1]);
    if (!preg_match('/^PORT (\d+)$/', trim((string)$line), $m)) {
        die("second server did not start\n");
    }
    register_shutdown_function(function () use ($proc, $pipes) {
        fclose($pipes[0]);
        fclose($pipes[1]);
        proc_terminate($proc);
        proc_close($proc);
    });
    return "http://127.0.0.1:{$m[1]}";
}
$other = other_server_start();

function slots($ch)
{
    $s = curl_downstream_slots($ch);
    return "write={$s['write']} header={$s['header']} read={$s['read']}";
}

foreach (['between execs', 'from a callback of another transfer'] as $mode) {
    echo "== reset $mode\n";
    $a = curl_init("$base/slow");
    $heads = 0;
    curl_setopt($a, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($a, CURLOPT_HEADERFUNCTION, function ($h, $l) use (&$heads) {
        $heads++;
        return strlen($l);
    });
    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $a);
    $b = null;
    $reset = false;
    $calls_before = null;
    do {
        curl_multi_exec($mh, $running);
        if (!$reset && $heads >= 5 && $b === null) {
            // a's head is in, its body is not
            echo "a during its transfer: ", slots($a), "\n";
            $calls_before = curl_downstream_dump($a)['slot_calls'];
            if ($mode === 'between execs') {
                curl_reset($a);
                $reset = true;
            } else {
                $b = curl_init("$other/json");
                curl_setopt($b, CURLOPT_WRITEFUNCTION, function ($h, $d) use ($a, &$reset) {
                    if (!$reset) {
                        curl_reset($a);
                        $reset = true;
                        echo "a after the reset: ", slots($a), "\n";
                    }
                    return strlen($d);
                });
                curl_multi_add_handle($mh, $b);
            }
            if ($reset) {
                echo "a after the reset: ", slots($a), "\n";
            }
        }
        while ($info = curl_multi_info_read($mh)) {
            echo "done: ", $info['handle'] === $a ? 'a' : 'b', " result=", $info['result'], "\n";
        }
        if ($running) {
            curl_multi_select($mh, 0.1);
        }
    } while ($running);
    echo "a content: ", var_export(curl_multi_getcontent($a), true), "\n";
    $calls_after = curl_downstream_dump($a)['slot_calls'];
    echo "calls of ours on a after the reset: ",
        ($calls_after['write'] - $calls_before['write']) + ($calls_after['header'] - $calls_before['header']), "\n";
    curl_multi_remove_handle($mh, $a);
    if ($b) {
        curl_multi_remove_handle($mh, $b);
    }
    echo "a at the end: ", slots($a), "\n";
    curl_multi_close($mh);
    echo "installed: ", curl_downstream_installed_count(), "\n";
}
?>
--EXPECT--
== reset between execs
a during its transfer: write=ours header=ours read=ours
a after the reset: write=pristine header=pristine read=pristine
0123456789done: a result=0
a content: NULL
calls of ours on a after the reset: 0
a at the end: write=pristine header=pristine read=pristine
installed: 0
== reset from a callback of another transfer
a during its transfer: write=ours header=ours read=ours
a after the reset: write=pristine header=pristine read=pristine
done: b result=0
0123456789done: a result=0
a content: NULL
calls of ours on a after the reset: 0
a at the end: write=pristine header=pristine read=pristine
installed: 0
