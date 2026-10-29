--TEST--
PHP2Shell mitigation (CVE-2026-109618/109619): with no WAF answer (appsec inactive under remote config, no request_init, a helper error) the WAF decision is left unchanged, and appsec's state still gates the mitigation
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--ENV--
DD_APPSEC_TESTING_PHP2SHELL_RUNTIME=full
--CGI--
--FILE--
<?php
// Runs under php-cgi (--CGI--): the mitigation is off under the cli SAPI. The
// runtime is forced to full (testing override). datadog.appsec.enabled is not
// set, so appsec is enabled via remote config.
//  1. config_sync enables appsec; request_init answers block.
//  2. request_init is answered with config_features(false): appsec becomes
//     inactive, the WAF did not run. The decision stays block; the gate
//     (headline state) closes regardless of it.
//  3. Inactive: only a config_sync, no request_init. Unchanged.
//  4. config_sync enables appsec again; request_init answers monitor.
//  5. request_init answered without a decision: unchanged.
//  6. The helper answers request_init with an error (and drops the client):
//     unchanged.
// request_init is sent in requests 1, 2, 4, 5 and 6, each with the address.
use function datadog\appsec\testing\{rinit, rshutdown};
require __DIR__ . '/inc/php2shell_cgi.php';
include __DIR__ . '/inc/mock_helper.php';

function rs() {
    return response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]));
}
function ri($settings) {
    return response_list(response_request_init([[['ok', []]], [], false, $settings]));
}
$helper = Helper::createInitedRun([
    response_list(response_config_features(true)),
    ri([P2S_WAF_SETTING => 'block']),
    rs(),
    response_list(response_config_features(false)),
    response_list(response_config_sync()),
    response_list(response_config_features(true)),
    ri([P2S_WAF_SETTING => 'monitor']),
    rs(),
    ri(new ArrayObject()),
    rs(),
    response_list(response('error', [])),
], ['no_wait_shutdown' => true]);

function show($label) {
    $minfo = p2s_minfo();
    echo "$label: appsec=", var_export(\datadog\appsec\is_enabled(), true),
        " state=", $minfo[P2S_ROW_STATE],
        " decision=", isset($minfo[P2S_ROW_DECISION]) ? $minfo[P2S_ROW_DECISION] : 'absent', "\n";
}

show('before any request');
$labels = [
    'enabled, block',
    'disabled by request_init',
    'inactive, config_sync only',
    'enabled again, monitor',
    'no decision',
    'helper error',
];
foreach ($labels as $label) {
    rinit();
    show($label);
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
before any request: appsec=false state=Active decision=pending
enabled, block: appsec=true state=Active decision=block
disabled by request_init: appsec=false state=Inactive decision=block
inactive, config_sync only: appsec=false state=Inactive decision=block
enabled again, monitor: appsec=true state=Active decision=monitor
no decision: appsec=true state=Active decision=monitor
helper error: appsec=true state=Active decision=monitor
addresses sent: full,full,full,full,full
