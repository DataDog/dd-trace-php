<?php
// Child script for php2shell_mitigation_waf_address.phpt and
// php2shell_mitigation_waf_sticky.phpt: one exchange with the mock helper, as
// appsec's RINIT has it (with datadog.appsec.testing on, the real RINIT skips
// the helper, so the testing rinit() and rshutdown() drive it). The
// request_init response carries the WAF decision P2S_WAF_ANSWER in its
// settings, or no decision when that is unset or empty. Prints
//   WAF ASKED address=V decision=V
// with address the value of php.php2shell.vulnerable in the request_init the
// helper received (absent when not sent) and decision the MINFO row after the
// response (absent without the row).
//
// With P2S_WAF_MARK set, for php-cgi -T: only the request that finds no file
// there does this (and creates it); the following ones run
// php2shell_child_waf.php, so that they start with the decision that this
// exchange left behind.
require_once __DIR__ . '/php2shell_cgi.php';

$mark = getenv('P2S_WAF_MARK');
if ($mark && file_exists($mark)) {
    require __DIR__ . '/php2shell_child_waf.php';
    return;
}
if ($mark) {
    touch($mark);
}

include __DIR__ . '/mock_helper.php';
$answer = getenv('P2S_WAF_ANSWER');
$settings = $answer ? [P2S_WAF_SETTING => $answer] : new ArrayObject();
$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]], [], false, $settings])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
]);
\datadog\appsec\testing\rinit();
\datadog\appsec\testing\rshutdown();
$minfo = p2s_minfo();
echo "WAF ASKED address=", p2s_waf_address($helper->get_command('request_init')),
    " decision=", isset($minfo[P2S_ROW_DECISION]) ? $minfo[P2S_ROW_DECISION] : 'absent', "\n";
