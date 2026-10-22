<?php
// Child script for the php2shell_mitigation_* tests that spawn php-cgi or
// php -S (see php2shell_cgi.php). $_POST, $_GET and $_FILES are built during
// request startup, before this runs, so reaching this line means the
// request-data parse completed without a fatal. Prints one line per request:
//   active       the MINFO headline is Active
//   resets       status()['post_deactivate_resets'], NA without
//                datadog.appsec.testing
//   tag          the p2s_tag request variable (POST, else GET), or -
//   hooks        the MINFO hook rows, name:value, spaces as underscores
//   peak         memory_get_peak_usage(true)
//   last_error   error_get_last()['message'] as JSON, read before anything
//                else runs in this request (always the last field)
// A p2s_set_allowed=0|1 query parameter first sets the appsec flag for later
// requests of this process (testing only), and is reported in active.
$p2s_last_error = error_get_last();
require_once __DIR__ . '/php2shell_cgi.php';
if (isset($_GET['p2s_set_allowed'])
        && function_exists('datadog\appsec\testing\php2shell_mitigation_set_allowed')) {
    datadog\appsec\testing\php2shell_mitigation_set_allowed((bool)$_GET['p2s_set_allowed']);
}
$p2s_status = function_exists('datadog\appsec\testing\php2shell_mitigation_status')
    ? datadog\appsec\testing\php2shell_mitigation_status() : null;
$p2s_minfo = p2s_minfo();
$p2s_hooks = [];
foreach (['treat_data', 'input_filter', 'rfc1867', 'deactivate', 'request_parse_body', 'read_post'] as $p2s_h) {
    $p2s_v = p2s_hook_row($p2s_minfo, $p2s_h);
    if ($p2s_v !== null) {
        $p2s_hooks[] = $p2s_h . ':' . str_replace(' ', '_', $p2s_v);
    }
}
echo "SCRIPT RAN",
    " active=", var_export(p2s_minfo_active(), true),
    " tag=", isset($_POST['p2s_tag']) ? $_POST['p2s_tag'] : (isset($_GET['p2s_tag']) ? $_GET['p2s_tag'] : '-'),
    " hooks=", implode(',', $p2s_hooks),
    " resets=", $p2s_status === null ? 'NA' : $p2s_status['post_deactivate_resets'],
    " post_fields=", count($_POST),
    " get_fields=", count($_GET),
    " files=", count($_FILES),
    " peak=", memory_get_peak_usage(true),
    " last_error=", json_encode($p2s_last_error === null ? null : $p2s_last_error['message']),
    "\n";
