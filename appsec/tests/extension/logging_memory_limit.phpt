--TEST--
Memory exhaustion while logging preserves PHP's user error handler for shutdown
--INI--
memory_limit=64M
display_errors=0
log_errors=0
datadog.appsec.log_file=php_error_reporting
--FILE--
<?php
use function datadog\appsec\testing\mlog;
use const datadog\appsec\testing\log_level\WARNING;

$warnings = 0;
$returned = false;
set_error_handler(function () use (&$warnings) {
    $warnings++;
    return true;
}, E_WARNING);
register_shutdown_function(function () use (&$warnings, &$returned) {
    ini_set('memory_limit', '64M');
    var_dump(!$returned && error_get_last()['type'] === E_ERROR);
    fopen(__DIR__ . '/does-not-exist-for-oom-test', 'r');
    var_dump($warnings);
});
$message = str_repeat('x', 500);
// Preallocate the slots so adding ballast does not resize the array.
$ballast = array_fill(0, 32768, null);
// Leave room for deferred reporting below patched PHP's hard limit.
$ballast[0] = str_repeat('x', 8 * 1024 * 1024);
gc_mem_caches();
// Measure a full chunk with small allocations, then fill the next one.
// The short log message must need a new page inside php_verror().
$limit = memory_get_usage(true);
$first = 0;
for ($i = 1; $i < 32768; $i++) {
    $ballast[$i] = str_repeat('x', 400);
    if (memory_get_usage(true) > $limit) {
        $limit = memory_get_usage(true);
        if ($first) {
            $end = $i + ($i - $first) - 1;
            for ($i++; $i < $end; $i++) {
                $ballast[$i] = str_repeat('x', 400);
            }
            break;
        }
        $first = $i;
    }
}
ini_set('memory_limit', (string)memory_get_usage(true));
// Only PHP without deferred OOM bails out inside php_verror(); patched PHP
// returns from mlog() before raising the deferred error.
mlog(WARNING, $message);
$returned = true;
?>
--EXPECT--
bool(true)
int(1)
