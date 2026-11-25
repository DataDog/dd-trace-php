--TEST--
FFE shared dependent-flag cases preserve results and native telemetry submissions
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_FEATURE_FLAGS_CONFIGURATION_SOURCE=remote_config
DD_METRICS_OTEL_ENABLED=0
--FILE--
<?php
require __DIR__ . '/../../../Common/FeatureFlags/ffe_api_bootstrap.inc';

function applies_to_php(array $item) {
    if (isset($item['_include'], $item['_skip'])) throw new RuntimeException('conflicting applicability controls');
    foreach (array('_include', '_skip') as $control) {
        if (!isset($item[$control])) continue;
        $matches = false;
        foreach ($item[$control] as $entry) $matches = $matches || $entry['platform'] === 'php';
        if (($control === '_include' && !$matches) || ($control === '_skip' && $matches)) return false;
    }
    return true;
}

function assert_same($actual, $expected, $label) {
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': got ' . json_encode($actual) . ', expected ' . json_encode($expected));
    }
}

function assert_event_matchers(array $events, array $matchers, $noUnmatched, $label) {
    // Matchers consume disjoint events in declaration order, including _count.
    foreach ($matchers as $matcher) {
        if (!applies_to_php($matcher)) continue;
        $remaining = $matcher['_count'] ?? 1;
        if (!is_int($remaining) || $remaining < 1) throw new RuntimeException('invalid matcher count');
        foreach ($events as $index => $event) {
            $matches = true;
            foreach ($matcher as $key => $expected) {
                if ($key === '_count' || $key === '_skip' || $key === '_include') continue;
                if (($event[$key] ?? null) !== $expected) $matches = false;
            }
            if ($matches) {
                unset($events[$index]);
                if (--$remaining === 0) break;
            }
        }
        assert_same($remaining, 0, $label . ' missing events for ' . json_encode($matcher));
    }
    if ($noUnmatched) assert_same(array_values($events), array(), $label . ' unmatched events');
}

$evaluations = array();
$exposures = array();
\DDTrace\install_hook('DDTrace\\Internal\\record_ffe_flag_evaluation', function ($hook) use (&$evaluations) {
    $args = $hook->args;
    $evaluations[] = array('flag' => $args[0], 'variant' => $args[1], 'allocation' => $args[2], 'errorCode' => $args[5]);
});
\DDTrace\install_hook('DDTrace\\Internal\\record_ffe_exposure', function ($hook) use (&$exposures) {
    $args = $hook->args;
    $exposures[] = array('flag' => $args[0], 'allocation' => $args[3], 'variant' => $args[4]);
});
$evaluator = \DDTrace\FeatureFlags\Internal\NativeEvaluator::create(false, null, true, function ($batch) {
    \DDTrace\FeatureFlags\Internal\NativeEvaluator::submitExposures($batch);
});
$types = array('BOOLEAN' => 'boolean', 'STRING' => 'string', 'INTEGER' => 'integer', 'NUMERIC' => 'float', 'JSON' => 'object');
$caseCount = 0;
$telemetryCount = 0;
// Feature suites are explicit opt-ins. Do not load unrelated suite configurations.
foreach (array('dependent-flags') as $suite) {
    $suiteRoot = $root . '/tests/FeatureFlags/ffe-system-test-data/evaluation-suites/' . $suite;
    $files = glob($suiteRoot . '/cases/*.json');
    if (!$files) throw new RuntimeException('missing canonical suite cases: ' . $suite);
    sort($files);
    $config = file_get_contents($suiteRoot . '/ufc-config.json');
    if ($config === false) throw new RuntimeException('missing canonical suite configuration');
    foreach ($files as $file) {
        $cases = json_decode(file_get_contents($file), true);
        if (!is_array($cases) || !$cases) throw new RuntimeException('empty or invalid canonical case file');
        foreach ($cases as $case) {
            if (!applies_to_php($case)) continue;
            ++$caseCount;
            // Every case starts with its own configuration snapshot and event window.
            assert_same(\DDTrace\Testing\ffe_load_config($config), true, 'load ' . $suite);
            $evaluations = $exposures = array();
            $details = $evaluator->evaluate($case['flag'], $types[$case['variationType']],
                $case['defaultValue'], $case['targetingKey'], $case['attributes']);
            $label = $suite . ': ' . $case['description'];
            foreach ($case['result'] as $field => $expected) {
                switch ($field) {
                    case 'value': $actual = $details->getValue(); break;
                    case 'reason': $actual = $details->getReason(); break;
                    case 'errorCode': $actual = $details->getErrorCode(); break;
                    default: throw new RuntimeException('unhandled canonical result field ' . $field);
                }
                assert_same($actual, $expected, $label . ' ' . $field);
            }
            if (!isset($case['expectations'])) continue;
            ++$telemetryCount;
            $expectations = $case['expectations'];
            $noUnmatched = $expectations['noUnmatchedEvents'] ?? false;
            if (isset($expectations['exposures'])) {
                assert_event_matchers($exposures, $expectations['exposures'], $noUnmatched, $label . ' exposures');
            }
            if (isset($expectations['evaluationEvents'])) {
                assert_event_matchers($evaluations, $expectations['evaluationEvents'], $noUnmatched, $label . ' evaluations');
            }
        }
    }
}
echo 'cases=', $caseCount, "\ntelemetry_cases=", $telemetryCount, "\n";
?>
--EXPECT--
cases=16
telemetry_cases=11
