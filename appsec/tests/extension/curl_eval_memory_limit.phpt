--TEST--
Downstream curl requests: the memory limit reached inside libcurl's PREREQ callback
--DESCRIPTION--
G10.15, G9.2, G9.6, G3.2: under a small memory_limit, the response call of
hop 0 of a redirect with a large head (made from the PREREQ callback of
hop 1, with no memory guard) crosses the limit while it turns the head into
headers. The collector's own appends are guarded and are never refused
here: the head fits, and a small transfer under the same limit finishes
with all its calls.

Two expectation sets, by PHP version:
- the allocator fails at once (the fatal error bails out): the callback's
  zend_try catches it, PREREQ aborts hop 1 (CURLE_ABORTED_BY_CALLBACK) and
  the fatal error is raised again once libcurl is back, at curl_exec();
- delayed out-of-memory (PHP 8.2.35+, 8.3.36+, 8.4.27+, 8.5.12+): the
  allocation succeeds and the error is queued, so the callbacks see nothing
  (they never rely on zend_try for it): the transfer goes on, hop 1 gets its
  W2, and the error surfaces at the next safe point, once curl_exec() is back.
Immediate set only: the torn transfers are repeated on one handle with
curl_exec() called from a tracer hook, whose sandbox swallows the fatal
error (the exception to G9.3, used here only to go on): every one of them
runs (a longjmp out of libcurl would leave the handle's in_callback flag
set, and the transfers after it would fail at once, G9.2), the descriptors
of the process (/proc/self/fd) and the C heap, where libcurl allocates, do
not grow over them, and no block verdict derives from the failed
allocation. With delayed out-of-memory no transfer is torn, and the sandbox
would discard the queued error and leave the request unusable (the hook
never runs again: G9.4, issue #4538, not tested per G10.15), so the
repetition is not done. In both sets the last transfer, outside any
sandbox, ends the request with the memory limit's fatal error.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call of hop 0 in PREREQ');
if (!function_exists('DDTrace\install_hook')) die('skip DDTrace\install_hook needed');
if (datadog\appsec\testing\curl_downstream_malloc_in_use() < 0) {
    die('skip glibc mallinfo needed to measure the C heap');
}
?>
--INI--
datadog.appsec.enabled=1
; PHP 8.5+: no stack trace with the fatal error
fatal_error_backtraces=0
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,curl_downstream_coll_refusals,curl_downstream_malloc_in_use};
require __DIR__ . '/inc/curl_eval.php';

function delayed_oom()
{
    $v = PHP_VERSION_ID;
    return ($v >= 80235 && $v < 80300) || ($v >= 80336 && $v < 80400) ||
        ($v >= 80427 && $v < 80500) || $v >= 80512;
}
function fds() { return count(scandir('/proc/self/fd')); }
// room for the collector (its fit check counts a whole 2 MiB chunk), not for
// the headers array of the large head (about 400 bytes per header line)
function small_limit() { ini_set('memory_limit', memory_get_usage(true) + (4 << 20)); }
// calls per subcontext, of the commands not seen yet
function calls_by_subctx(Helper $helper)
{
    $by = [];
    foreach (ce_execs($helper) as list($id, $last, $data)) {
        $by[$id] = (isset($by[$id]) ? $by[$id] : 0) + 1;
    }
    return $by;
}

$delayed = delayed_oom();
$helper = null;
register_shutdown_function(function () use (&$helper, $delayed) {
    ini_set('memory_limit', '-1');
    $by = calls_by_subctx($helper);
    echo "\nlast transfer: ", json_encode($by), "\n";
    // delayed: transfer 2 (after the small one) goes on with both hops whole;
    // immediate: transfer 16 (after 14 torn ones) is aborted in PREREQ, after
    // the response call of hop 0
    $expected = $delayed ? $by === ['curl-2-0' => 2, 'curl-2-1' => 2]
        : $by === ['curl-16-0' => 1];
    echo 'last transfer as expected: ', $expected ? 'yes' : 'no', "\n";
    echo 'collector appends refused: ', curl_downstream_coll_refusals(), "\n";
});
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 80, 'ok'), false);
rinit();
echo 'expectation set: ', $delayed ? 'delayed out-of-memory' : 'immediate', "\n";

// a transfer that finishes under the small limit
small_limit();
$ch = curl_init("$base/redirect");
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
ini_set('memory_limit', '-1');
echo 'small transfer: ', $res, ', ', json_encode(calls_by_subctx($helper)), "\n";
echo 'collector appends refused: ', curl_downstream_coll_refusals(), "\n";
if (PHP_VERSION_ID < 80500) { // deprecated (a no-op since 8.0)
    curl_close($ch);
}

// the same handle for every torn transfer: a fatal error leaks the references
// held by the frames it unwinds, so a new handle per transfer would keep
// libcurl's descriptors of each one open
$ch = curl_init("$base/manyheaders?n=15000&redirect=1");
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
function transfer() {}
DDTrace\install_hook('transfer', function () use ($ch) {
    small_limit();
    curl_exec($ch);
});
$fds = [];
$heap = [];
$errnos = [];
$torn = 0;
$w2 = 0;
for ($i = 0; $i < 14 && !$delayed; $i++) {
    transfer();
    ini_set('memory_limit', '-1');
    $errnos[] = curl_errno($ch);
    $torn += curl_errno($ch) === CURLE_ABORTED_BY_CALLBACK;
    $by = calls_by_subctx($helper);
    $w2 += isset($by['curl-' . ($i + 2) . '-1']);
    $fds[] = fds();
    $heap[] = curl_downstream_malloc_in_use();
}
if ($delayed) {
    echo "repeated torn transfers: as expected (none with delayed out-of-memory)\n";
} else {
    // from the end of the second transfer: the first ones set up what the
    // handle keeps (connection and DNS caches, buffers)
    $fd_growth = $fds[13] - $fds[1];
    $heap_growth = $heap[13] - $heap[1];
    $bad = [];
    if ($torn !== 14 || $w2 !== 0) {
        $bad[] = "torn $torn, with a W2 for hop 1 $w2, errors " . implode(',', $errnos);
    }
    if ($fd_growth !== 0) {
        $bad[] = "descriptors opened over 12 transfers: $fd_growth";
    }
    // a longjmp out of libcurl leaks 8-35 KB per transfer
    if ($heap_growth >= (32 << 10)) {
        $bad[] = "C heap growth over 12 transfers: $heap_growth (limit 32 KiB)";
    }
    echo 'repeated torn transfers: ',
        $bad ? 'not as expected: ' . implode('; ', $bad) : 'as expected (14 torn, no W2 for hop 1, no descriptor or C heap growth)',
        "\n";
}
echo "not blocked\n";

small_limit();
curl_exec($ch);
echo "not reached\n";
?>
--EXPECTF--
expectation set: %s
small transfer: {"status":"ok","n":42}, {"curl-1-0":2,"curl-1-1":2}
collector appends refused: 0
repeated torn transfers: as expected (%s)
not blocked

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d

last transfer: %s
last transfer as expected: yes
collector appends refused: 0
