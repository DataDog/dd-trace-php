<?php

namespace DDTrace\Tests\FeatureFlags;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\NativeEvaluator;

/**
 * Loads the `scale` and `scale-json` suites of the pinned ffe-system-test-data
 * submodule and checks each case through the PHP evaluation path:
 * NativeEvaluator, then ResultMapper. The conformance test and the benchmark
 * share this class, so the benchmark measures only cases that pass.
 */
final class ScaleSuite
{
    const SCALE = 'scale';
    const SCALE_JSON = 'scale-json';

    public static function suiteRoot($suite)
    {
        return __DIR__ . '/ffe-system-test-data/evaluation-suites/' . $suite;
    }

    /**
     * @return string[] Case file names without the .json suffix, sorted.
     */
    public static function caseFiles($suite)
    {
        $files = glob(self::suiteRoot($suite) . '/cases/*.json');
        if ($files === false || count($files) === 0) {
            throw new \RuntimeException('no case files found for suite ' . $suite
                . '; run git submodule update --init tests/FeatureFlags/ffe-system-test-data');
        }
        sort($files);

        return array_map(function ($file) {
            return basename($file, '.json');
        }, $files);
    }

    public static function loadConfig($suite)
    {
        $json = self::readFile(self::suiteRoot($suite) . '/ufc-config.json');
        if (!\DDTrace\Testing\ffe_load_config($json)) {
            throw new \RuntimeException('failed to load the ' . $suite . ' configuration');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function cases($suite, $caseFile)
    {
        $path = self::suiteRoot($suite) . '/cases/' . $caseFile . '.json';
        $cases = json_decode(self::readFile($path), true);
        if (!is_array($cases)) {
            throw new \RuntimeException('failed to decode ' . $path . ': ' . json_last_error_msg());
        }

        return $cases;
    }

    public static function expectedType(array $case)
    {
        switch ($case['variationType']) {
            case 'BOOLEAN':
                return EvaluationType::BOOLEAN;
            case 'STRING':
                return EvaluationType::STRING;
            case 'INTEGER':
                return EvaluationType::INTEGER;
            case 'NUMERIC':
                return EvaluationType::FLOAT;
            case 'JSON':
                return EvaluationType::OBJECT;
        }

        throw new \RuntimeException('unsupported variationType ' . json_encode($case['variationType']));
    }

    /**
     * @return EvaluationDetails
     */
    public static function evaluate(NativeEvaluator $evaluator, array $case)
    {
        return $evaluator->evaluate(
            $case['flag'],
            self::expectedType($case),
            $case['defaultValue'],
            $case['targetingKey'],
            is_array($case['attributes']) ? $case['attributes'] : array()
        );
    }

    /**
     * Checks the value and the reason. Values compare strictly, so an integer
     * that comes back as a float, or a reordered object, is a failure.
     *
     * @return string|null A description of the mismatch, or null.
     */
    public static function check(EvaluationDetails $details, array $case)
    {
        $expected = $case['result'];
        $failures = array();

        if (!self::valuesMatch($details->getValue(), $expected['value'], $case['variationType'])) {
            $failures[] = 'value got=' . self::describe($details->getValue())
                . ' want=' . self::describe($expected['value']);
        }

        if (array_key_exists('reason', $expected) && $details->getReason() !== $expected['reason']) {
            $failures[] = 'reason got=' . json_encode($details->getReason())
                . ' want=' . json_encode($expected['reason']);
        }

        if ($details->getErrorCode() !== null) {
            $failures[] = 'error=' . $details->getErrorCode() . ' ' . $details->getErrorMessage();
        }

        return $failures ? implode('; ', $failures) : null;
    }

    /**
     * Evaluates and checks every case of a case file.
     *
     * @return string[] One entry per failing case.
     */
    public static function checkAll(NativeEvaluator $evaluator, $suite, $caseFile)
    {
        $failures = array();
        foreach (self::cases($suite, $caseFile) as $index => $case) {
            $failure = self::check(self::evaluate($evaluator, $case), $case);
            if ($failure !== null) {
                $failures[] = $suite . '/' . $caseFile . '#' . $index . ' ' . $case['flag'] . ': ' . $failure;
            }
        }

        return $failures;
    }

    private static function valuesMatch($actual, $expected, $variationType)
    {
        if ($variationType === 'NUMERIC') {
            return is_float($actual) && is_numeric($expected) && abs($actual - (float) $expected) < 0.000001;
        }

        return $actual === $expected;
    }

    private static function describe($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > 200) {
            return substr($json, 0, 200) . '... (' . strlen($json) . ' bytes)';
        }

        return $json;
    }

    private static function readFile($path)
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('failed to read ' . $path);
        }

        return $contents;
    }
}
