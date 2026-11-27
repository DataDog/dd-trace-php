<?php
// Helpers for the curl_eval_*.phpt tests: the WAF calls the extension makes
// for downstream curl requests, as received by the mock helper, rendered in a
// stable form.

require_once __DIR__ . '/mock_helper.php';
require_once __DIR__ . '/curl_downstream.php';

// keep only what the WAF calls need, as in production
datadog\appsec\testing\curl_downstream_collect_all(false);

// A mock helper answering request_init, then the request_exec commands with
// the given verdicts ('ok', or 'block' to block with a 403), then
// request_shutdown.
function ce_helper(array $verdicts, $with_shutdown = true)
{
    $responses = [response_list(response_request_init([[['ok', []]]]))];
    foreach ($verdicts as $v) {
        if ($v === 'block') {
            $responses[] = response_list(response_request_exec([
                [['block', ['status_code' => '403', 'type' => 'json']]],
                ['{"found":"attack"}'],
            ]));
        } else {
            $responses[] = response_list(response_request_exec([[['ok', []]]]));
        }
    }
    if ($with_shutdown) {
        $responses[] = response_list(response_request_shutdown(
            [[['ok', []]], new ArrayObject(), new ArrayObject()]));
    }
    return Helper::createInitedRun($responses);
}

function ce_norm($v)
{
    if (is_string($v)) {
        $v = preg_replace('/127\.0\.0\.1:\d+/', '127.0.0.1:PORT', $v);
        return preg_replace('/boundary=-*[0-9a-zA-Z]+/', 'boundary=BOUNDARY', $v);
    }
    if (!is_array($v)) {
        return $v;
    }
    $out = [];
    foreach ($v as $k => $vv) {
        if (is_string($k) && $k !== 'host' && cd_unstable_header($k)) {
            continue;
        }
        $out[$k] = ce_norm($vv);
    }
    if (array_keys($out) !== range(0, count($out) - 1)) {
        ksort($out);
    }
    return $out;
}

// [[subctx_id, last_call, data], ...] of the request_exec commands
function ce_execs(Helper $helper)
{
    return ce_execs_of($helper->get_commands());
}

// the same, from the commands already read (get_commands() reads them once)
function ce_execs_of($cmds)
{
    $res = [];
    foreach ((array)$cmds as $c) {
        if ($c[0] !== 'request_exec') {
            continue;
        }
        $opts = isset($c[1][1]) ? $c[1][1] : [];
        $res[] = [
            isset($opts['subctx_id']) ? $opts['subctx_id'] : null,
            !empty($opts['subctx_last_call']),
            ce_norm($c[1][0]),
        ];
    }
    return $res;
}

function ce_print(array $execs)
{
    foreach ($execs as list($id, $last, $data)) {
        echo $id, $last ? ' last' : '', ' ',
            json_encode($data, JSON_UNESCAPED_SLASHES), "\n";
    }
}

// Checks that every subcontext got exactly one last call, as its final call.
// Below libcurl 7.80 (no CURLOPT_PREREQFUNCTION) there is only hop 0, and its
// last call is the one without data (G6.11, G10.6); what breaks that is
// printed, so the expected output is the same on every libcurl.
function ce_check_closed(array $execs)
{
    $below_7_80 = datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'] < 0x075000;
    $last = [];
    foreach ($execs as list($id, $is_last, $data)) {
        if (isset($last[$id]) && $last[$id]) {
            echo "call to $id after its last call\n";
        }
        if ($below_7_80 && !isset($last[$id]) && !preg_match('/-0$/', (string)$id)) {
            echo "$id: a hop of 1 or more below libcurl 7.80\n";
        }
        if ($below_7_80 && $is_last && $data) {
            echo "$id: last call with data below libcurl 7.80\n";
        }
        $last[$id] = $is_last;
    }
    foreach ($last as $id => $is_last) {
        if (!$is_last) {
            echo "$id not closed\n";
        }
    }
    echo count($last), " subcontext(s), all closed\n";
}

// For the blocking tests: prints the WAF calls and the requests the server
// got, after the request was aborted.
function ce_print_at_shutdown(Helper $helper, $log)
{
    register_shutdown_function(function () use ($helper, $log) {
        echo "\n== WAF calls\n";
        ce_print(ce_execs($helper));
        echo "== server log\n";
        echo @file_get_contents($log);
        @unlink($log);
    });
}
