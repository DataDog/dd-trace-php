--TEST--
Trace attributes on preloaded functions are hooked
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000)
    die('skip: No attributes pre-PHP 8');
?>
--INI--
opcache.enable_cli=1
opcache.preload={PWD}/include_trace_attribute.php
opcache.protect_memory=1
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
if (!extension_loaded('Zend OPcache')) die("opcache is required for this test\n");

var_dump(class_exists('Datadog\PreloadedTraced', false));

Datadog\PreloadedTraced::method();
Datadog\PreloadedTraced::method();
Datadog\preloaded_function();

// A class linked during the request which uses a preloaded trait
final class TraitUser
{
    use Datadog\PreloadedTracedTrait;
}

(new TraitUser())->viaTrait();
(new TraitUser())->viaTrait();

$names = array_column(dd_trace_serialize_closed_spans(), 'name');
sort($names);
echo implode("\n", $names), "\n";
?>
--EXPECT--
bool(true)
method: preloaded.method rsrc preloaded
method: preloaded.method rsrc preloaded
function: preloaded.function
trait: preloaded.trait
trait: preloaded.trait
preloaded.function
preloaded.method
preloaded.method
preloaded.trait
preloaded.trait
