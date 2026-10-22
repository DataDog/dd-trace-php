--TEST--
PHP2Shell mitigation (CVE-2026-109619 B): the post_deactivate reset clears the request-owned engine globals
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
--INI--
extension=ddtrace.so
memory_limit=128M
--CGI--
--FILE--
<?php
// Runs under php-cgi (--CGI--): the mitigation is off under the cli SAPI.
// White-box check of the reset. The helper snapshots the request-owned engine
// globals (the user error/exception handlers and their stacks, the
// error-recording state, EG(exception)/error_handling, the $_GET/$_POST/...
// super-global slots, the object store's bucket pointer and top), writes
// sentinels into them, runs _reset_request_owned_globals(), records whether
// every field came back clean, and restores the snapshot so this request is
// unaffected. A real post-bailout stale state cannot be reproduced from
// userland (a debug build always does a slow shutdown, and init_executor
// re-inits each field before any reachable read), so this proves the reset
// clears exactly the fields it must. post_deactivate_hook checks that the
// module hook really runs it at request shutdown, and gated on appsec.
//
// The last-error clear is not part of this reset, and the helper does not
// report it: it runs from the sapi_module.deactivate wrapper, and
// php2shell_mitigation_sapi_deactivate_clear_last_error drives it end to end.
function all_clean($r) {
    foreach ($r as $field => $clean) {
        if ($clean !== true) { echo "NOT RESET: $field\n"; return false; }
    }
    return true;
}
$s = datadog\appsec\testing\php2shell_mitigation_status();
$raw = datadog\appsec\testing\php2shell_mitigation_post_deactivate_reset();
// post_deactivate_resets counts only resets done by the real module hook at
// request shutdown; the probe's call must not be counted.
$s_after = datadog\appsec\testing\php2shell_mitigation_status();

echo "enough_fields=", var_export(count($raw) >= 9, true), "\n";
echo "objects_store=", var_export($raw["objects_store"] ?? null, true), "\n";
echo "raw_all_reset=", var_export(all_clean($raw), true), "\n";
echo "probe_not_counted=", var_export(
    $s_after['post_deactivate_resets'] === $s['post_deactivate_resets'], true), "\n";

// The snapshot was restored, so normal error handling still works in this
// same request (the reset left the engine in a working state).
set_error_handler(function ($n, $s) { echo "handled: $s\n"; return true; });
trigger_error("after-reset", E_USER_WARNING);
restore_error_handler();
echo "request still alive\n";
--EXPECT--
enough_fields=true
objects_store=true
raw_all_reset=true
probe_not_counted=true
handled: after-reset
request still alive
