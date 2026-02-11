<?php

namespace DDTrace\Tests\FeatureFlags;

use DDTrace\Tests\Common\BaseTestCase;

final class EvaluationTest extends BaseTestCase
{
    private static $configLoaded = false;
    private static $configFlagKeys = [];

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();

        if (!extension_loaded('ddtrace')) {
            self::markTestSkipped('ddtrace extension not loaded');
        }

        $configPath = __DIR__ . '/fixtures/config/ufc-config.json';
        $json = file_get_contents($configPath);
        self::$configLoaded = \dd_trace_internal_fn('ffe_load_config', $json);
        if (!self::$configLoaded) {
            self::fail('Failed to load UFC config from ' . $configPath);
        }

        // Track which flags exist in the config so we can distinguish
        // "flag not in config" from "flag exists but no matching allocation"
        $config = json_decode($json, true);
        if (isset($config['flags'])) {
            self::$configFlagKeys = array_keys($config['flags']);
        }
    }

    /**
     * Map variation type string to the integer type ID expected by ffe_evaluate.
     *   0 = string, 1 = integer, 2 = float, 3 = boolean, 4 = object (JSON)
     */
    private static function variationTypeToId($variationType)
    {
        $map = [
            'STRING'  => 0,
            'INTEGER' => 1,
            'NUMERIC' => 2,
            'BOOLEAN' => 3,
            'JSON'    => 4,
        ];
        return isset($map[$variationType]) ? $map[$variationType] : -1;
    }

    /**
     * Build the attributes array for ffe_evaluate from the test case attributes.
     * Only scalar types (string, number, bool) are supported by the FFI bridge.
     */
    private static function buildAttributes(array $attrs)
    {
        $result = [];
        foreach ($attrs as $key => $value) {
            if (is_string($value) || is_numeric($value) || is_bool($value)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * Parse the value_json string returned by ffe_evaluate based on the variation type.
     */
    private static function parseValueJson($valueJson, $variationType)
    {
        switch ($variationType) {
            case 'STRING':
                return json_decode($valueJson, true);
            case 'INTEGER':
                return (int) $valueJson;
            case 'NUMERIC':
                return (float) $valueJson;
            case 'BOOLEAN':
                return $valueJson === 'true';
            case 'JSON':
                return json_decode($valueJson, true);
            default:
                return json_decode($valueJson, true);
        }
    }

    /**
     * Data provider that scans all evaluation case fixture files and flattens
     * every scenario into a [fileName, caseIndex, caseData] tuple.
     */
    public function provideEvaluationCases()
    {
        $casesDir = __DIR__ . '/fixtures/evaluation-cases';
        $files = glob($casesDir . '/*.json');
        $dataset = [];

        foreach ($files as $filePath) {
            $fileName = basename($filePath, '.json');
            $cases = json_decode(file_get_contents($filePath), true);

            foreach ($cases as $index => $case) {
                $label = sprintf('%s#%d (%s)', $fileName, $index, $case['flag']);
                $dataset[$label] = [$fileName, $index, $case];
            }
        }

        return $dataset;
    }

    /**
     * @dataProvider provideEvaluationCases
     */
    public function testEvaluation($fileName, $caseIndex, $case)
    {
        if (!self::$configLoaded) {
            $this->markTestSkipped('UFC config was not loaded');
        }

        $flagKey = $case['flag'];
        $variationType = $case['variationType'];
        $typeId = self::variationTypeToId($variationType);
        $targetingKey = isset($case['targetingKey']) ? $case['targetingKey'] : '';
        $attributes = isset($case['attributes']) ? self::buildAttributes($case['attributes']) : [];
        $defaultValue = isset($case['defaultValue']) ? $case['defaultValue'] : null;
        $expectedValue = $case['result']['value'];

        // Skip test cases that reference flags not present in the UFC config
        // AND expect a non-default result (these require a different config).
        if (!in_array($flagKey, self::$configFlagKeys) && $expectedValue !== $defaultValue) {
            $this->markTestSkipped(
                sprintf('Flag "%s" not in UFC config and expected non-default value', $flagKey)
            );
        }

        $result = \dd_trace_internal_fn('ffe_evaluate', $flagKey, $typeId, $targetingKey, $attributes);

        $this->assertNotNull(
            $result,
            sprintf('ffe_evaluate returned null for %s#%d', $fileName, $caseIndex)
        );

        $this->assertArrayHasKey('value_json', $result);

        $errorCode = isset($result['error_code']) ? (int) $result['error_code'] : 0;

        // When the evaluator returns an error, the Provider layer would return
        // the defaultValue. If the expected result equals the defaultValue,
        // verify the evaluator correctly returned an error (no match).
        if ($errorCode !== 0 && $expectedValue === $defaultValue) {
            // Evaluator correctly could not resolve — Provider returns default.
            $this->assertTrue(true);
            return;
        }

        // error_code=0 with reason=1 means DefaultAllocationNull (no matching
        // allocation). Same Provider-level default behavior applies.
        $reason = isset($result['reason']) ? (int) $result['reason'] : -1;
        if ($errorCode === 0 && $reason === 1 && $expectedValue === $defaultValue) {
            $this->assertTrue(true);
            return;
        }

        $actualValue = self::parseValueJson($result['value_json'], $variationType);

        if ($variationType === 'NUMERIC') {
            $this->assertEquals(
                $expectedValue,
                $actualValue,
                sprintf('Value mismatch for %s#%d (flag=%s)', $fileName, $caseIndex, $flagKey),
                1e-10
            );
        } else {
            $this->assertSame(
                $expectedValue,
                $actualValue,
                sprintf('Value mismatch for %s#%d (flag=%s): expected %s, got %s',
                    $fileName, $caseIndex, $flagKey,
                    json_encode($expectedValue), json_encode($actualValue))
            );
        }
    }
}
