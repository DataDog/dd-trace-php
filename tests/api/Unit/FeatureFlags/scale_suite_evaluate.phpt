--TEST--
FFE scale and scale-json suites evaluate through NativeEvaluator with the expected values and reasons
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) {
    die('skip: the scale suites are too slow for valgrind');
}
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_FEATURE_FLAGS_CONFIGURATION_SOURCE=remote_config
--INI--
memory_limit=-1
--FILE--
<?php
require __DIR__ . '/../../../Common/FeatureFlags/ffe_api_bootstrap.inc';
require_once $root . '/tests/FeatureFlags/ScaleSuite.php';

use DDTrace\FeatureFlags\Internal\NativeEvaluator;
use DDTrace\Tests\FeatureFlags\ScaleSuite;

$evaluator = NativeEvaluator::create(false);

foreach (array(ScaleSuite::SCALE, ScaleSuite::SCALE_JSON) as $suite) {
    ScaleSuite::loadConfig($suite);
    $cases = 0;
    $failures = array();
    foreach (ScaleSuite::caseFiles($suite) as $caseFile) {
        $cases += count(ScaleSuite::cases($suite, $caseFile));
        // Evaluate twice: the second pass is a repeat evaluation of the same
        // flag, variant and configuration.
        $failures = array_merge(
            $failures,
            ScaleSuite::checkAll($evaluator, $suite, $caseFile),
            ScaleSuite::checkAll($evaluator, $suite, $caseFile)
        );
    }
    foreach ($failures as $failure) {
        echo "failure=" . $failure . "\n";
    }
    echo $suite . ': cases=' . ($cases > 0 ? 'nonzero' : 'zero') . ' failures=' . count($failures) . "\n";
}
?>
--EXPECT--
scale: cases=nonzero failures=0
scale-json: cases=nonzero failures=0
