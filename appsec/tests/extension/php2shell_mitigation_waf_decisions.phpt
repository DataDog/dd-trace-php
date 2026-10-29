--TEST--
PHP2Shell mitigation (CVE-2026-109618/109619): under mode auto, each explicit WAF decision in a request_init response (none, monitor, block) replaces the process-wide decision; a response without one, or with an unknown value, leaves it unchanged; every request_init carries php.php2shell.vulnerable
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test.log
--ENV--
DD_APPSEC_TESTING_PHP2SHELL_RUNTIME=full
--CGI--
--FILE--
<?php
// Runs under php-cgi (--CGI--): the mitigation is off under the cli SAPI. The
// runtime is forced to full (testing override), so the test also runs on a
// patched runtime. Each rinit() sends a request_init; the mock helper's
// response carries the decision in its settings map (key
// php2shell_mitigation). The decision is the MINFO row, read after each
// response: it starts pending (armed, "start enabled") and is sticky: it
// changes only with the next explicit decision. Requests 4 and 6 bring none
// (no key, and a value that is not a decision), so the previous decision
// stays. The address is sent in every request_init, whatever the decision
// (the helper evaluates it, or answers from its cache).
use function datadog\appsec\testing\{rinit, rshutdown};
require __DIR__ . '/inc/php2shell_cgi.php';
include __DIR__ . '/inc/mock_helper.php';

$answers = [
    'block' => [P2S_WAF_SETTING => 'block'],
    'monitor' => [P2S_WAF_SETTING => 'monitor'],
    'none' => [P2S_WAF_SETTING => 'none'],
    'no decision' => new ArrayObject(),
    'block again' => [P2S_WAF_SETTING => 'block'],
    'unknown value' => [P2S_WAF_SETTING => 'bogus'],
    'none again' => [P2S_WAF_SETTING => 'none'],
];
$responses = [];
foreach ($answers as $settings) {
    $responses[] = response_list(response_request_init([[['ok', []]], [], false, $settings]));
    $responses[] = response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]));
}
$helper = Helper::createInitedRun($responses);

function decision() {
    $minfo = p2s_minfo();
    return isset($minfo[P2S_ROW_DECISION]) ? $minfo[P2S_ROW_DECISION] : 'absent';
}

echo "before any request: decision=", decision(), "\n";
foreach (array_keys($answers) as $label) {
    rinit();
    echo "answer $label: decision=", decision(), "\n";
    rshutdown();
}

$addresses = [];
foreach ((array)$helper->get_commands() as $c) {
    if ($c[0] === 'request_init') {
        $addresses[] = p2s_waf_address($c);
    }
}
echo "addresses sent: ", implode(',', $addresses), "\n";
--EXPECT--
before any request: decision=pending
answer block: decision=block
answer monitor: decision=monitor
answer none: decision=none
answer no decision: decision=none
answer block again: decision=block
answer unknown value: decision=block
answer none again: decision=none
addresses sent: full,full,full,full,full,full,full
