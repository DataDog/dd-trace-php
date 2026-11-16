<?php
/**
 * Reproducer for Pattern B of the AppSec helper "unexpected command" bug.
 *
 * PHP's request shutdown order (main/main.c: php_request_shutdown) is:
 *   1. php_call_shutdown_functions()  – register_shutdown_function callbacks
 *   2. zend_call_destructors()        – PHP object __destruct()
 *   3. php_output_end_all()           – output-buffer flush (frees OB memory)
 *   4. zend_unset_timeout()
 *   5. zend_deactivate_modules()      – extension RSHUTDOWN hooks; each wrapped
 *                                       in bare zend_try{}zend_end_try() that
 *                                       SWALLOWS bailouts, leaving the worker alive
 *   ...
 *   9. (super-)globals destroyed      – $GLOBALS freed here, AFTER step 5
 *
 * ddappsec's RSHUTDOWN (step 5) calls dd_request_shutdown() via
 * _do_request_finish_php(). Inside _dd_command_exec() the outgoing message is
 * built by the _request_pack() callback BEFORE _omsg_send() writes anything to
 * the socket. _request_pack() calls dd_entity_body_convert(), which parses the
 * captured JSON response body into PHP arrays via emalloc().
 *
 * If emalloc() exhausts the memory_limit, zend_mm_safe_error() calls
 * zend_bailout(). Because $GLOBALS survives until step 9 (after RSHUTDOWN),
 * the large allocation here stays alive during step 5. The bailout is caught
 * silently by zend_deactivate_modules()'s per-module zend_try/zend_end_try().
 * The worker process survives. But _omsg_send() was never reached, so the
 * helper never received RequestShutdown and is still in its inner request loop.
 *
 * The next request to the SAME PHP-FPM worker:
 *   – dd_helper_mgr_acquire_conn() returns the still-open connection
 *     (dd_helper_rshutdown() was never called, so connected_this_req is still
 *     true from the previous RINIT and _mgr.conn is non-NULL)
 *   – dd_request_init() sends RequestInit on that connection
 *   – The Rust helper's inner loop sees RequestInit instead of
 *     RequestExec/RequestShutdown and logs:
 *         error in request loop: unexpected command RequestInit(...)
 *
 * Requires pm.max_children=1 so that the triggering request and the follow-up
 * are handled by the same worker / the same helper socket.
 */

// Set a tight memory_limit before any large allocation so it governs both the
// script and the RSHUTDOWN phase.
ini_set('memory_limit', '32M');

// Hold ~28 MiB in a superglobal.  Superglobals are destroyed at step 9 of
// php_request_shutdown — AFTER the extension RSDHUTDOWNs at step 5 — so this
// pressure is fully visible to the memory allocator during dd_request_shutdown.
$GLOBALS['_dd_rshutdown_pressure'] = str_repeat("\0", 28 * 1024 * 1024);

// Emit a ~400 KiB JSON body (inside the default DD_APPSEC_MAX_BODY_BUFF_SIZE
// cap of 512 KiB).  ddappsec captures this via its zend_write hook into a
// persistent (malloc-backed) buffer.  During dd_request_shutdown(),
// dd_entity_body_convert() parses it into PHP arrays with emalloc(): roughly
// 400 arrays × 50 string-keyed properties × ~110 bytes/property ≈ 2.2 MiB.
// With ~1 MiB remaining under the 32 MiB ceiling (28 MiB pressure + ~3 MiB
// PHP/extension overhead), this overflows the heap and triggers the bailout.
header('Content-Type: application/json');
$proto = [];
for ($i = 1; $i <= 50; $i++) {
    $proto["key$i"] = str_repeat('v', 10);
}
// array_fill shares one copy of $proto via copy-on-write during script
// execution (negligible memory), but json_encode serialises each element in
// full, and later dd_entity_body_convert() creates 400 independent PHP arrays.
echo json_encode(array_fill(0, 400, $proto));
