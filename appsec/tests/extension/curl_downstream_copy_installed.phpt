--TEST--
Downstream curl requests: a handle copied while our functions are installed
--DESCRIPTION--
G5.2, G5.4, G10.23: curl_copy_handle() of a handle whose transfer is in
flight copies our three functions onto the copy (ext/curl re-points only their
data to the copy's php_curl *). The copy hook does not touch them. While the
source is still in flight, the copy makes its own transfer (a redirect): at
its start it is normalised (ext/curl's functions back), passes the guard and
gets ours installed for its own transfer; its hops are its own (curl-2-*)
and none is attributed to the source (curl-1-*), and the calls of our
functions on the copy are counted for the copy. At the end both handles have
ext/curl's functions back.
Copies made at the same time (curl_copy_handle(), and on PHP 8 a clone) that
then run a transfer that is skipped (file://, reason=scheme) are normalised
too, at that transfer's start: the app's callback already sees ext/curl's
functions, gets the data, and they stay afterwards.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the hops and the write/header slots');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_slots,curl_downstream_dump};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 20, 'ok'));

function slots($ch)
{
    $s = curl_downstream_slots($ch);
    return "write={$s['write']} header={$s['header']} read={$s['read']} data={$s['data']}";
}

var_dump(rinit());
$src = curl_init("$base/slow");
$copy = null;
$skipped = [];
curl_setopt($src, CURLOPT_RETURNTRANSFER, true);
curl_setopt($src, CURLOPT_HEADERFUNCTION, function ($h, $line) use (&$copy, &$skipped) {
    if ($copy === null) {
        $copy = curl_copy_handle($h);
        $skipped['copy'] = curl_copy_handle($h);
        // clone of a resource is an error: a second copy on PHP 7
        $skipped['clone'] = PHP_VERSION_ID >= 80000 ? clone $h : curl_copy_handle($h);
        echo "copied in the source's header callback\n";
    }
    return strlen($line);
});
$mh = curl_multi_init();
curl_multi_add_handle($mh, $src);
$copied_ran = false;
do {
    curl_multi_exec($mh, $running);
    if ($copy !== null && !$copied_ran) {
        $copied_ran = true;
        echo "source still in flight: ", var_export((bool)$running, true), "\n";
        echo "copy before its transfer: ", slots($copy), "\n";
        foreach ($skipped as $k => $sk) {
            echo "$k before its skipped transfer: ", slots($sk), "\n";
            $seen = null;
            $len = 0;
            curl_setopt_array($sk, [
                CURLOPT_URL => 'file://' . __FILE__,
                CURLOPT_WRITEFUNCTION => function ($h, $data) use (&$seen, &$len) {
                    $seen = slots($h);
                    $len += strlen($data);
                    return strlen($data);
                },
            ]);
            echo "$k: ", var_export(curl_exec($sk), true), "\n";
            $d = curl_downstream_dump($sk);
            echo "$k: observed=", var_export($d['observed'], true), " reason={$d['reason']}\n";
            echo "$k got the file: ", var_export($len === filesize(__FILE__), true), "\n";
            echo "$k during its skipped transfer: $seen\n";
            echo "$k after its skipped transfer: ", slots($sk), "\n";
        }
        $during = null;
        curl_setopt_array($copy, [
            CURLOPT_URL => "$base/redirect",
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADERFUNCTION => function ($h, $line) use (&$during) {
                $during = slots($h);
                return strlen($line);
            },
        ]);
        echo "copy: ", curl_exec($copy), "\n";
        echo "copy during its transfer: $during\n";
        echo "copy after its transfer: ", slots($copy), "\n";
    }
    if ($running) {
        curl_multi_select($mh, 0.1);
    }
} while ($running);
echo "source: ", curl_multi_getcontent($src), "\n";
curl_multi_remove_handle($mh, $src);
echo "source after its transfer: ", slots($src), "\n";
$ds = curl_downstream_dump($src);
$dc = curl_downstream_dump($copy);
echo "calls of ours on the source: header=", $ds['slot_calls']['header'],
    " write bytes=", $ds['slot_bytes']['write'], "\n";
echo "calls of ours on the copy: header=", $dc['slot_calls']['header'],
    " write bytes=", $dc['slot_bytes']['write'], "\n";
curl_multi_close($mh);
var_dump(rshutdown());
$execs = ce_execs($helper);
foreach ($execs as list($id, $last)) {
    echo $id, $last ? ' last' : '', "\n";
}
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
copied in the source's header callback
source still in flight: true
copy before its transfer: write=ours header=ours read=ours data=own
copy before its skipped transfer: write=ours header=ours read=ours data=own
copy: true
copy: observed=false reason=scheme
copy got the file: true
copy during its skipped transfer: write=pristine header=pristine read=pristine data=own
copy after its skipped transfer: write=pristine header=pristine read=pristine data=own
clone before its skipped transfer: write=ours header=ours read=ours data=own
clone: true
clone: observed=false reason=scheme
clone got the file: true
clone during its skipped transfer: write=pristine header=pristine read=pristine data=own
clone after its skipped transfer: write=pristine header=pristine read=pristine data=own
copy: {"status":"ok","n":42}
copy during its transfer: write=ours header=ours read=ours data=own
copy after its transfer: write=pristine header=pristine read=pristine data=own
source: 0123456789
source after its transfer: write=pristine header=pristine read=pristine data=own
calls of ours on the source: header=5 write bytes=10
calls of ours on the copy: header=11 write bytes=22
bool(true)
curl-1-0
curl-2-0
curl-2-0 last
curl-2-1
curl-2-1 last
curl-1-0 last
3 subcontext(s), all closed
