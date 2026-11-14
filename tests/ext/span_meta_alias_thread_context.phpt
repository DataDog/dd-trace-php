--TEST--
Legacy root service, env and version aliases update the shared OTel thread context
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70400) die('skip: FFI requires PHP 7.4+');
if (PHP_OS_FAMILY !== 'Linux') die('skip: Linux only');
if (!extension_loaded('ffi')) die('skip: ffi extension required');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_SERVICE=configured-service
DD_ENV=configured-env
DD_VERSION=configured-version
--FILE--
<?php
require __DIR__ . '/includes/otel_thread_context.inc';

$context = new OtelThreadContext();
$root = DDTrace\start_span();
function show_context($label, $context) {
    $attrs = $context->attributes();
    echo $label, ': ', json_encode([$attrs[1], $attrs[2], $attrs[3]]), "\n";
}

$root->meta['service.name'] = 'alias-service';
show_context('service', $context);
$root->meta['env'] = 'alias-env';
show_context('env', $context);
$root->meta['version'] = 'alias-version';
show_context('version', $context);
// The thread context falls back to configured env/version for empty properties, as with direct writes.
$root->meta['env'] = null;
show_context('null env', $context);
unset($root->meta['version']);
show_context('unset version', $context);
$root->meta = ['service.name' => 'bulk-service', 'env' => 'bulk-env', 'version' => 'bulk-version'];
show_context('bulk', $context);
unset($root->meta['service.name']);
show_context('unset service', $context);
DDTrace\close_span();
?>
--EXPECT--
service: ["alias-service","configured-env","configured-version"]
env: ["alias-service","alias-env","configured-version"]
version: ["alias-service","alias-env","alias-version"]
null env: ["alias-service","configured-env","alias-version"]
unset version: ["alias-service","configured-env","configured-version"]
bulk: ["bulk-service","bulk-env","bulk-version"]
unset service: ["unnamed-php-service","bulk-env","configured-version"]
