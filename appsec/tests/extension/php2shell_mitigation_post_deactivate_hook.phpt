--TEST--
PHP2Shell mitigation (CVE-2026-109619 B): the module post_deactivate hook runs the reset at real request shutdown, gated on appsec being enabled and on the toggle
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
--INI--
extension=ddtrace.so
memory_limit=128M
--FILE--
<?php
// php-cgi -T 2 serves two requests in one process, running the full
// php_request_shutdown() -- including every module's post_deactivate hook --
// between them. status()['post_deactivate_resets'] counts how many times the
// production hook passed the gate and ran the reset, so request #2 observes
// what request #1's shutdown did. Each child prints one line per request.
require __DIR__ . '/inc/php2shell_cgi.php';

function show($label, $r) {
    $parts = [];
    foreach (p2s_child_lines($r) as $req) {
        $parts[] = "active={$req['active']} resets={$req['resets']}";
    }
    echo "$label: ", $parts ? implode(' | ', $parts) : "NO OUTPUT: {$r['out']}{$r['err']}", "\n";
}

// Default test configuration: appsec not explicitly configured (enabled via
// remote config) on an unpatched runtime, so the mitigation is active and the
// hook must have run once by the time request #2 executes.
show('enabled', p2s_cgi_run([], ['-T', '2', P2S_CHILD_SCRIPT]));

// Appsec explicitly disabled, by INI or by environment: the gate is closed,
// the hook never resets.
show('appsec_ini_0', p2s_cgi_run(['datadog.appsec.enabled' => '0'], ['-T', '2', P2S_CHILD_SCRIPT]));
show('appsec_env_0', p2s_cgi_run([], ['-T', '2', P2S_CHILD_SCRIPT], ['DD_APPSEC_ENABLED' => '0']));

// Mitigation toggled off: likewise.
show('mode_off', p2s_cgi_run([], ['-T', '2', P2S_CHILD_SCRIPT], ['DD_APPSEC_PHP2SHELL_MITIGATION' => 'off']));
--EXPECT--
enabled: active=true resets=0 | active=true resets=1
appsec_ini_0: active=false resets=0 | active=false resets=0
appsec_env_0: active=false resets=0 | active=false resets=0
mode_off: active=false resets=0 | active=false resets=0
