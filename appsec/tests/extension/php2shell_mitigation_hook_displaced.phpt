--TEST--
PHP2Shell mitigation: hooks written by a module initialised after ddappsec are captured at startup and chained
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_unless_stub();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
--FILE--
<?php
// p2s_stub.so, loaded after ddappsec with p2s_stub.minit=1, registers in its
// MINIT an input_filter pair that does not chain (as ext/filter does), a
// treat_data handler that delegates to php_default_treat_data (as mbstring
// does), and a read_post handler and a php_rfc1867_callback that forward. The
// hooks are captured once, from the zend_extension startup hook, after every
// MINIT, so:
//  - MINFO reports the treat_data, input_filter, php_rfc1867_callback and
//    read_post wrappers installed;
//  - the stub's read_post, captured as the inner one, reads the POST body;
//  - the stub's rfc1867 callback, captured as the inner one, sees the
//    MULTIPART_EVENT_FILE_START of an upload, and the upload is registered;
//  - the stub's filter, captured as the inner one, still runs for every
//    request variable: it rewrites the first byte of p2s_tag;
//  - its input_filter_init runs once per request (2 over -T 2);
//  - a single large field crossing memory_limit is deferred.
require __DIR__ . '/inc/php2shell_cgi.php';
$stub = ['p2s_stub.minit' => '1'];
$after = ['ext_after' => true];

$r = p2s_cgi_run($stub + ['memory_limit' => '128M'], [], [], 'p2s_tag=abc&b=1', $after);
$lines = p2s_child_lines($r);
if (!$lines) {
    p2s_dump($r, 'post');
} else {
    echo "post: active={$lines[0]['active']} tag={$lines[0]['tag']} post_fields={$lines[0]['post_fields']}\n";
    echo "post: hooks={$lines[0]['hooks']}\n";
}
echo "post: ", preg_match('/P2S_STUB filter=(\d+) init=(\d+) treat=\d+ read=(\d+)/', $r['err'], $m)
    ? "stub filter={$m[1]} init={$m[2]} read_chained=" . var_export($m[3] > 0, true)
    : "no stub line: {$r['err']}", "\n";

$r = p2s_cgi_run($stub + ['memory_limit' => '128M', 'file_uploads' => '1'], [], p2s_multipart_env('b'),
    p2s_multipart_body([['p2s_tag', 'abc'], ['up', 'hello', 'a.txt']], 'b'), $after);
$lines = p2s_child_lines($r);
if (!$lines) {
    p2s_dump($r, 'upload');
} else {
    echo "upload: tag={$lines[0]['tag']} files={$lines[0]['files']}\n";
}
echo "upload: ", preg_match('/P2S_STUB filter=(\d+) .* file_start=(\d+)/', $r['err'], $m)
    ? "stub filter={$m[1]} file_start={$m[2]}" : "no stub line: {$r['err']}", "\n";

$r = p2s_cgi_run($stub, ['-T', '2'], p2s_cgi_env(P2S_CHILD_SCRIPT, 'GET', ['QUERY_STRING' => 'p2s_tag=abc']), null, $after);
$tags = [];
foreach (p2s_child_lines($r) as $line) {
    $tags[] = $line['tag'];
}
echo "get_T2: tags=", implode(',', $tags), " ", preg_match('/P2S_STUB filter=(\d+) init=(\d+)/', $r['err'], $m)
    ? "stub filter={$m[1]} init={$m[2]}" : "no stub line: {$r['err']}", "\n";

$body = 'f' . str_repeat('Z', 2000000) . '[k]=1';
$peak = p2s_calibrate($stub, [], $body, $after);
$soft_mib = max(4, (int)round($peak * 0.7 / P2S_MIB));
$soft = $soft_mib * P2S_MIB;
$r = p2s_cgi_run($stub + ['memory_limit' => "{$soft_mib}M", 'post_max_size' => '32M'], [], [], $body, $after);
$outcome = p2s_outcome($r, $soft);
echo "single_field: outcome=$outcome\n";
if ($outcome !== 'deferred') {
    p2s_dump($r, "peak=$peak soft=$soft");
}
--EXPECTF--
post: active=true tag=Xbc post_fields=2
post: hooks=treat_data:Installed,input_filter:Installed,rfc1867:Installed,deactivate:Installed%Aread_post:Installed
post: stub filter=2 init=1 read_chained=true
upload: tag=Xbc files=1
upload: stub filter=1 file_start=1
get_T2: tags=Xbc,Xbc stub filter=2 init=2
single_field: outcome=deferred
