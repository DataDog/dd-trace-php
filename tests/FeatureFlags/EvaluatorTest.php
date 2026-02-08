<?php

/**
 * Standalone test for the UFC Evaluator.
 * Run with: php tests/FeatureFlags/EvaluatorTest.php
 */

require_once __DIR__ . '/../../src/DDTrace/FeatureFlags/LRUCache.php';
require_once __DIR__ . '/../../src/DDTrace/FeatureFlags/Evaluator.php';
require_once __DIR__ . '/../../src/DDTrace/FeatureFlags/ExposureWriter.php';
require_once __DIR__ . '/../../src/DDTrace/FeatureFlags/Provider.php';

use DDTrace\FeatureFlags\Evaluator;

$errors = 0;
$passed = 0;

function assertEqual($expected, $actual, $message) {
    global $errors, $passed;
    if ($expected !== $actual) {
        echo "FAIL: $message\n";
        echo "  Expected: " . json_encode($expected) . "\n";
        echo "  Actual:   " . json_encode($actual) . "\n";
        $errors++;
    } else {
        $passed++;
    }
}

// Load the UFC fixture from system-tests
$fixtureDir = '/Users/leo.romanovsky/go/src/github.com/DataDog/system-tests/tests/parametric/test_ffe';
$flagsFile = $fixtureDir . '/flags-v1.json';
if (!file_exists($flagsFile)) {
    echo "ERROR: Cannot find flags-v1.json fixture at $flagsFile\n";
    exit(1);
}

$config = json_decode(file_get_contents($flagsFile), true);
$evaluator = new Evaluator();
$evaluator->setConfig($config);

// Load and run all test case files
$testCaseFiles = glob($fixtureDir . '/test-*.json');
foreach ($testCaseFiles as $testCaseFile) {
    $fileName = basename($testCaseFile);
    $testCases = json_decode(file_get_contents($testCaseFile), true);
    if (!is_array($testCases)) {
        echo "SKIP: $fileName (invalid JSON)\n";
        continue;
    }

    foreach ($testCases as $i => $testCase) {
        $flag = $testCase['flag'];
        $variationType = $testCase['variationType'];
        $defaultValue = $testCase['defaultValue'];
        $targetingKey = $testCase['targetingKey'];
        $attributes = isset($testCase['attributes']) ? $testCase['attributes'] : [];
        $expectedValue = $testCase['result']['value'];

        $context = [
            'targeting_key' => $targetingKey,
            'attributes' => $attributes,
        ];

        $result = $evaluator->resolveFlag($flag, $variationType, $context);

        // Determine actual value
        $actualValue = $defaultValue;
        if ($result !== null && !isset($result['error_code']) && $result['variant'] !== null && $result['value'] !== null) {
            $actualValue = $result['value'];
        }

        assertEqual(
            $expectedValue,
            $actualValue,
            "$fileName case $i: flag='$flag' targetingKey='$targetingKey'"
        );
    }
}

echo "\n";
echo "Results: $passed passed, $errors failed\n";
if ($errors > 0) {
    exit(1);
}
echo "All tests passed!\n";
