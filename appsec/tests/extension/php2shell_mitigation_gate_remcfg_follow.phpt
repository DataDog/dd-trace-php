--TEST--
PHP2Shell mitigation (CVE-2026-109618): with appsec enabled via remote config, the gate follows the activation state the helper reports
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--CGI--
--FILE--
<?php
// Runs under php-cgi (--CGI--): the mitigation is off under the cli SAPI.
// datadog.appsec.enabled is not set, so appsec is enabled via remote config.
// The "mitigation allowed" flag starts out true. After a config sync, it
// follows the activation state the helper reported: cleared while appsec is
// inactive, set again once it is active. Without a sync, it is left alone.
use function datadog\appsec\testing\{rinit, rshutdown};
require __DIR__ . '/inc/php2shell_cgi.php';
include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_config_features(false)), // first rinit: inactive
    response_list(response_config_features(true)),  // second rinit: active
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
]);

function show($label) {
    $minfo = p2s_minfo();
    echo "$label: appsec=", var_export(\datadog\appsec\is_enabled(), true),
        " state=", $minfo[P2S_ROW_STATE], "\n";
}

show('before sync');
rinit();
show('features false');
rshutdown();
rinit();
show('features true');
rshutdown();
--EXPECT--
before sync: appsec=false state=Active
features false: appsec=false state=Inactive
features true: appsec=true state=Active
