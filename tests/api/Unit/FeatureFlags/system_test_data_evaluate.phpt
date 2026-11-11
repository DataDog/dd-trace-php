--TEST--
FFE canonical system test data evaluates through the Datadog client
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) {
    die('skip: canonical FFE fixture sweep is too slow for valgrind');
}
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
require __DIR__ . '/../../../Common/FeatureFlags/ffe_api_bootstrap.inc';
$fixtureRoot = $root . '/tests/FeatureFlags/ffe-system-test-data';

final class FfeFixtureLogger implements \DDTrace\Log\LoggerInterface
{
    public function debug($message, array $context = array())
    {
    }

    public function warning($message, array $context = array())
    {
    }

    public function error($message, array $context = array())
    {
    }

    public function isLevelActive($level)
    {
        return false;
    }
}

$caseFiles = glob($fixtureRoot . '/evaluation-cases/*.json');
if ($caseFiles === false) {
    throw new \RuntimeException('failed to glob evaluation case fixtures');
}
sort($caseFiles);

if (count($caseFiles) === 0) {
    throw new \RuntimeException('no evaluation-case fixture files found under ' . $fixtureRoot);
}

$applicableCases = array();
$excludedCount = 0;
$failures = array();
foreach ($caseFiles as $caseFile) {
    $cases = decode_json_file($caseFile);
    if (!is_array($cases)) {
        $failures[] = basename($caseFile) . ': root JSON value is not an array';
        continue;
    }

    foreach ($cases as $index => $case) {
        if (!fixture_case_applies($case, 'php')) {
            $excludedCount++;
            continue;
        }
        $applicableCases[] = array(basename($caseFile), $index, $case);
    }
}

$caseCount = 0;
if (count($applicableCases) === 0) {
    show('config_loaded', false);
} else {
    $configPath = $fixtureRoot . '/ufc-config.json';
    if (!is_file($configPath)) {
        throw new \RuntimeException('missing fixture config: ' . $configPath);
    }
    $configJson = file_get_contents($configPath);
    if ($configJson === false) {
        throw new \RuntimeException('failed to read fixture config: ' . $configPath);
    }
    if (!\DDTrace\Testing\ffe_load_config($configJson)) {
        throw new \RuntimeException('failed to load fixture config: ' . $configPath);
    }
    show('config_loaded', true);
    $client = new \DDTrace\FeatureFlags\Client(new FfeFixtureLogger());
    foreach ($applicableCases as $entry) {
        list($fileName, $index, $case) = $entry;
        $caseCount++;
        try {
            run_fixture_case($client, $fileName, $index, $case, $failures);
        } catch (\Throwable $exception) {
            $failures[] = $fileName . '#' . $index . ': ' . $exception->getMessage();
        }
    }
}

foreach ($failures as $failure) {
    echo "failure=" . $failure . "\n";
}

show('fixture_files', count($caseFiles));
show('cases', $caseCount);
show('excluded', $excludedCount);
show('failures', count($failures));

function fixture_case_applies(array $case, $platform)
{
    foreach ($case['_skip'] ?? array() as $entry) {
        if ($entry['platform'] === $platform) {
            return false;
        }
    }
    if (array_key_exists('_include', $case)) {
        foreach ($case['_include'] as $entry) {
            if ($entry['platform'] === $platform) {
                return true;
            }
        }
        return false;
    }
    return true;
}

function run_fixture_case($client, $fileName, $index, array $case, array &$failures)
{
    foreach (array('flag', 'variationType', 'defaultValue', 'targetingKey', 'attributes', 'result') as $requiredKey) {
        if (!array_key_exists($requiredKey, $case)) {
            $failures[] = $fileName . '#' . $index . ': missing key ' . $requiredKey;
            return;
        }
    }

    $context = array(
        'targetingKey' => $case['targetingKey'],
        'attributes' => is_array($case['attributes']) ? $case['attributes'] : array(),
    );

    $details = evaluate_fixture_case(
        $client,
        $case['variationType'],
        $case['flag'],
        $case['defaultValue'],
        $context
    );

    $providerState = $details->getProviderState();
    if (($providerState['productionRuntime'] ?? null) !== true) {
        $failures[] = $fileName . '#' . $index . ': productionRuntime must be true';
    }
    if (($providerState['reason'] ?? null) !== 'ready') {
        $failures[] = $fileName . '#' . $index
            . ': runtime reason got=' . encode_value($providerState['reason'] ?? null)
            . ' want="ready"';
    }

    if (!array_key_exists('value', $case['result'])) {
        $failures[] = $fileName . '#' . $index . ': result must include value';
        return;
    }

    if (!values_match($details->getValue(), $case['result']['value'], $case['variationType'])) {
        $failures[] = $fileName . '#' . $index
            . ': value got=' . encode_value($details->getValue())
            . ' want=' . encode_value($case['result']['value']);
    }

    $exposureData = $details->getExposureData();
    foreach (array('serialId', 'doLog') as $key) {
        if (!array_key_exists($key, $case['result'])) {
            continue;
        }
        $actual = array_key_exists($key, $exposureData) ? $exposureData[$key] : null;
        if ($actual !== $case['result'][$key]) {
            $failures[] = $fileName . '#' . $index . ': ' . $key
                . ' got=' . encode_value($actual)
                . ' want=' . encode_value($case['result'][$key]);
        }
    }

}

function evaluate_fixture_case($client, $variationType, $flag, $defaultValue, array $context)
{
    switch ($variationType) {
        case 'BOOLEAN':
            return $client->getBooleanDetails($flag, $defaultValue, $context);
        case 'STRING':
            return $client->getStringDetails($flag, $defaultValue, $context);
        case 'INTEGER':
            return $client->getIntegerDetails($flag, $defaultValue, $context);
        case 'NUMERIC':
            return $client->getFloatDetails($flag, $defaultValue, $context);
        case 'JSON':
            return $client->getObjectDetails($flag, $defaultValue, $context);
    }

    throw new \RuntimeException('unsupported variationType ' . encode_value($variationType));
}

function values_match($actual, $expected, $variationType)
{
    if ($variationType === 'NUMERIC') {
        return is_numeric($actual)
            && is_numeric($expected)
            && abs((float) $actual - (float) $expected) < 0.000001;
    }

    if (is_array($actual) || is_array($expected)) {
        return arrays_match($actual, $expected);
    }

    return $actual === $expected;
}

function arrays_match($actual, $expected)
{
    if (!is_array($actual) || !is_array($expected)) {
        return false;
    }

    if (count($actual) !== count($expected)) {
        return false;
    }

    foreach ($expected as $key => $expectedValue) {
        if (!array_key_exists($key, $actual)) {
            return false;
        }

        $actualValue = $actual[$key];
        if (is_array($actualValue) || is_array($expectedValue)) {
            if (!arrays_match($actualValue, $expectedValue)) {
                return false;
            }
            continue;
        }

        if (is_float($actualValue) || is_float($expectedValue)) {
            if (!is_float($actualValue) || !is_float($expectedValue)) {
                return false;
            }
            if (abs($actualValue - $expectedValue) >= 0.000001) {
                return false;
            }
            continue;
        }

        if ($actualValue !== $expectedValue) {
            return false;
        }
    }

    return true;
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

function encode_value($value)
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function show($label, $value)
{
    echo $label . '=' . encode_value($value) . "\n";
}
?>
--EXPECTF--
config_loaded=%s
fixture_files=%d
cases=%d
excluded=%d
failures=0
