--TEST--
FFE targeting regex conformance evaluates through the libdatadog rules-based engine
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') {
    die('skip: regex conformance fixtures are not shipped in the PECL test package');
}
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) {
    die('skip: regex conformance fixture sweep is too slow for valgrind');
}
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
$root = getenv('TEST_PHP_SRCDIR');
if (!is_string($root) || $root === '') {
    $root = dirname(dirname(dirname(__DIR__)));
}

$fixturePath = $root
    . '/tests/FeatureFlags/ffe-system-test-data/regex-conformance/targeting-regex-conformance.json';
$fixture = decode_json_file($fixturePath);
if (!isset($fixture['cases']) || !is_array($fixture['cases'])) {
    throw new \RuntimeException('regex conformance fixture must contain a cases array');
}

$caseCount = 0;
$rustOverrideCount = 0;
$observableCount = 0;
$expectedCompileFailures = 0;
$loadRejections = 0;
$evaluationRejections = 0;
$failures = array();

foreach ($fixture['cases'] as $case) {
    $caseCount++;
    $id = isset($case['id']) ? $case['id'] : '#' . ($caseCount - 1);
    $rustExpectations = rust_rules_based_expectations($case);
    if ($rustExpectations !== null) {
        $rustOverrideCount++;
    }

    $expectedCompile = expected_value($case, $rustExpectations, 'compile', 'expectedCompile');
    $expectedMatch = expected_value($case, $rustExpectations, 'match', 'expectedMatch');
    if (!is_bool($expectedCompile)) {
        $failures[] = $id . ': no observable Rust compile expectation';
        continue;
    }
    if ($expectedCompile && !is_bool($expectedMatch)) {
        $failures[] = $id . ': no observable Rust match expectation';
        continue;
    }
    if (!isset($case['rawPattern']) || !is_string($case['rawPattern'])) {
        $failures[] = $id . ': rawPattern must be a string';
        continue;
    }
    if (!isset($case['input']) || !is_string($case['input'])) {
        $failures[] = $id . ': input must be a string';
        continue;
    }

    $observableCount++;
    if (!$expectedCompile) {
        $expectedCompileFailures++;
    }

    $configJson = json_encode(build_regex_config($case['rawPattern']), JSON_UNESCAPED_SLASHES);
    if (!is_string($configJson)) {
        $failures[] = $id . ': failed to encode UFC config';
        continue;
    }

    $loaded = \DDTrace\Testing\ffe_load_config($configJson);
    if (!$loaded) {
        if ($expectedCompile) {
            $failures[] = $id . ': UFC config rejected an expected-valid regex';
        } else {
            $loadRejections++;
        }
        continue;
    }

    $result = \DDTrace\ffe_evaluate(
        'regex-conformance',
        0,
        $id,
        array('input' => $case['input'])
    );

    if (!is_object($result)) {
        $failures[] = $id . ': native evaluator did not return an object';
        continue;
    }

    $value = json_decode($result->valueJson, true);
    if (!$expectedCompile) {
        // The native bridge has no standalone regex-compile API. Paired MATCHES and
        // NOT_MATCHES allocations make compilation observable: a valid regex must
        // select one allocation. Invalid patterns currently surface as CONFIG_PARSE;
        // a rules implementation may instead fail both conditions closed to default.
        $isParseError = $result->reason === 5 && $result->errorCode === 2 && $value === null;
        $isFailClosed = $result->reason === 1 && $value === null;
        if (!$isParseError && !$isFailClosed) {
            $failures[] = $id . ': invalid regex compiled or matched, got=' . encode_result($result);
        } else {
            $evaluationRejections++;
        }
        continue;
    }

    $expectedValue = $expectedMatch ? 'matched' : 'not-matched';
    if ($result->reason !== 2 || $value !== $expectedValue) {
        $failures[] = $id . ': got=' . encode_result($result) . ' want=' . $expectedValue;
    }
}

foreach ($failures as $failure) {
    echo 'failure=' . $failure . "\n";
}

show('fixture_cases', $caseCount);
show('rust_overrides', $rustOverrideCount);
show('observable_cases', $observableCount);
show('expected_compile_failures', $expectedCompileFailures);
show('load_rejections', $loadRejections);
show('evaluation_rejections', $evaluationRejections);
show('failures', count($failures));

function rust_rules_based_expectations(array $case)
{
    if (!isset($case['engineExpectations']) || !is_array($case['engineExpectations'])) {
        return null;
    }
    if (!isset($case['engineExpectations']['rustRulesBased'])
        || !is_array($case['engineExpectations']['rustRulesBased'])) {
        return null;
    }

    return $case['engineExpectations']['rustRulesBased'];
}

function expected_value(array $case, $rustExpectations, $engineKey, $commonKey)
{
    if (is_array($rustExpectations) && array_key_exists($engineKey, $rustExpectations)) {
        return $rustExpectations[$engineKey];
    }

    return array_key_exists($commonKey, $case) ? $case[$commonKey] : null;
}

function build_regex_config($pattern)
{
    return array(
        'createdAt' => '2024-01-01T00:00:00Z',
        'environment' => array('name' => 'regex-conformance'),
        'flags' => array(
            'regex-conformance' => array(
                'key' => 'regex-conformance',
                'enabled' => true,
                'variationType' => 'STRING',
                'variations' => array(
                    'matched' => array('key' => 'matched', 'value' => 'matched'),
                    'not-matched' => array('key' => 'not-matched', 'value' => 'not-matched'),
                ),
                'allocations' => array(
                    regex_allocation('matches', 'MATCHES', 'matched', $pattern),
                    regex_allocation('not-matches', 'NOT_MATCHES', 'not-matched', $pattern),
                ),
            ),
        ),
    );
}

function regex_allocation($key, $operator, $variationKey, $pattern)
{
    return array(
        'key' => $key,
        'rules' => array(array(
            'conditions' => array(array(
                'attribute' => 'input',
                'operator' => $operator,
                'value' => $pattern,
            )),
        )),
        'splits' => array(array(
            'variationKey' => $variationKey,
            'shards' => array(),
        )),
        'doLog' => false,
    );
}

function decode_json_file($path)
{
    $json = file_get_contents($path);
    if ($json === false) {
        throw new \RuntimeException('failed to read ' . $path);
    }

    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \RuntimeException('failed to decode ' . $path . ': ' . json_last_error_msg());
    }

    return $decoded;
}

function encode_result($result)
{
    return json_encode(array(
        'valueJson' => $result->valueJson,
        'reason' => $result->reason,
        'errorCode' => $result->errorCode,
    ), JSON_UNESCAPED_SLASHES);
}

function show($label, $value)
{
    echo $label . '=' . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
}
?>
--EXPECTF--
fixture_cases=75
rust_overrides=25
observable_cases=75
expected_compile_failures=19
load_rejections=%d
evaluation_rejections=%d
failures=0
