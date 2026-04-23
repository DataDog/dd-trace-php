<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature;

use PHPUnit\Framework\TestCase;

/**
 * Cross-tracer evaluation parity test.
 *
 * Loads the shared UFC config + evaluation-case fixtures originally authored for
 * dd-trace-go (openfeature/testdata/) and drives each case through the PHP FFE
 * bridge (DDTrace\ffe_load_config + DDTrace\ffe_evaluate).
 *
 * The fixtures live in-tree at tests/OpenFeature/testdata/. When updating, copy
 * the latest versions from dd-trace-go so all SDKs validate against the same
 * reference set.
 *
 * Semantics the test enforces (matches what an OpenFeature client observes):
 *   - reason in {DEFAULT, DISABLED, ERROR}  -> OpenFeature provider returns
 *     the caller-supplied defaultValue; raw bridge value_json may be "null".
 *   - reason in {STATIC, TARGETING_MATCH, SPLIT} -> raw bridge value must
 *     match the fixture value.
 *
 * Reason equivalence:
 *   - STATIC <-> TARGETING_MATCH are treated as interchangeable ("successful
 *     match") because libdatadog-rs and dd-trace-go's reference evaluator
 *     classify a subset of matches differently. Value correctness is the
 *     invariant; reason taxonomy drift is tracked separately.
 *   - DEFAULT <-> DISABLED are interchangeable (both produce defaultValue).
 */
final class FfeFixturesTest extends TestCase
{
    private const TYPE_STRING  = 0;
    private const TYPE_INTEGER = 1;
    private const TYPE_FLOAT   = 2;
    private const TYPE_BOOLEAN = 3;
    private const TYPE_OBJECT  = 4;

    private const REASON_NAMES = [
        0 => 'STATIC',
        1 => 'DEFAULT',
        2 => 'TARGETING_MATCH',
        3 => 'SPLIT',
        4 => 'DISABLED',
        5 => 'ERROR',
    ];

    private const VARIATION_TO_TYPE_ID = [
        'BOOLEAN' => self::TYPE_BOOLEAN,
        'STRING'  => self::TYPE_STRING,
        'INTEGER' => self::TYPE_INTEGER,
        'NUMERIC' => self::TYPE_FLOAT,
        'JSON'    => self::TYPE_OBJECT,
    ];

    private const FALLBACK_REASONS = ['DEFAULT', 'DISABLED', 'ERROR'];

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('DDTrace\\ffe_load_config') || !function_exists('DDTrace\\ffe_evaluate')) {
            self::markTestSkipped('ddtrace extension with FFE bindings not loaded');
        }

        $configPath = __DIR__ . '/testdata/ufc-config.json';
        $json = file_get_contents($configPath);
        self::assertNotFalse($json, "failed to read {$configPath}");

        $loaded = \DDTrace\ffe_load_config($json);
        self::assertTrue($loaded, 'ffe_load_config returned false for ufc-config.json');
    }

    /**
     * @dataProvider fixtureProvider
     *
     * @param array<string, mixed> $case
     */
    public function testFixtureCase(string $fixtureFile, int $index, array $case): void
    {
        $flag = (string) $case['flag'];
        $variationType = (string) $case['variationType'];
        $targetingKey = isset($case['targetingKey']) ? (string) $case['targetingKey'] : null;
        $attributes = $case['attributes'] ?? [];
        $expected = $case['result'];
        $defaultValue = $case['defaultValue'] ?? null;

        $this->assertArrayHasKey($variationType, self::VARIATION_TO_TYPE_ID, "unknown variationType {$variationType}");
        $typeId = self::VARIATION_TO_TYPE_ID[$variationType];

        $filteredAttrs = [];
        foreach ($attributes as $k => $v) {
            if (is_scalar($v)) {
                $filteredAttrs[(string) $k] = $v;
            }
        }

        $tk = ($targetingKey === null || $targetingKey === '') ? null : $targetingKey;

        $result = \DDTrace\ffe_evaluate($flag, $typeId, $tk, $filteredAttrs);
        $this->assertIsArray($result, 'ffe_evaluate returned null for ' . $flag);

        $reasonCode = (int) ($result['reason'] ?? -1);
        $reason = self::REASON_NAMES[$reasonCode] ?? ('UNKNOWN(' . $reasonCode . ')');
        $expectedReason = (string) $expected['reason'];

        $label = sprintf('[%s #%d flag=%s]', basename($fixtureFile), $index, $flag);

        $this->assertTrue(
            $this->reasonsEquivalent($expectedReason, $reason),
            "{$label} reason mismatch: expected {$expectedReason}, got {$reason}"
        );

        $valueJson = $result['value_json'] ?? null;
        $this->assertIsString($valueJson, "{$label} value_json missing");
        $rawValue = json_decode($valueJson, true);
        $this->assertSame(
            JSON_ERROR_NONE,
            json_last_error(),
            "{$label} value_json not valid JSON: " . (string) $valueJson
        );

        // Effective value = what an OpenFeature client sees. On fallback reasons
        // the provider substitutes defaultValue for the (usually null) bridge value.
        $effectiveValue = in_array($reason, self::FALLBACK_REASONS, true)
            ? $defaultValue
            : $rawValue;

        $this->assertValuesEqual(
            $expected['value'],
            $effectiveValue,
            "{$label} value mismatch"
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: array<string, mixed>}>
     */
    public static function fixtureProvider(): iterable
    {
        $pattern = __DIR__ . '/testdata/evaluation-cases/*.json';
        $files = glob($pattern);
        self::assertNotFalse($files, "glob failed for {$pattern}");
        self::assertNotEmpty($files, "no fixture files matched {$pattern}");

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            self::assertNotFalse($raw, "cannot read {$file}");
            $cases = json_decode($raw, true);
            self::assertIsArray($cases, "fixture is not an array: {$file}");

            foreach ($cases as $i => $case) {
                $label = basename($file) . '#' . $i . '/' . ($case['targetingKey'] ?? '');
                yield $label => [$file, (int) $i, $case];
            }
        }
    }

    private function reasonsEquivalent(string $expected, string $actual): bool
    {
        if ($expected === $actual) {
            return true;
        }

        $matchSet = ['STATIC', 'TARGETING_MATCH', 'SPLIT'];
        if (in_array($expected, $matchSet, true) && in_array($actual, $matchSet, true)) {
            return true;
        }

        $fallbackSet = self::FALLBACK_REASONS;
        if (in_array($expected, $fallbackSet, true) && in_array($actual, $fallbackSet, true)) {
            return true;
        }

        return false;
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    private function assertValuesEqual($expected, $actual, string $message): void
    {
        if (is_float($expected) || is_float($actual)) {
            $this->assertEqualsWithDelta((float) $expected, (float) $actual, 1e-9, $message);
            return;
        }

        if (is_array($expected) && is_array($actual)) {
            $this->assertSame(
                json_encode(self::normalize($expected)),
                json_encode(self::normalize($actual)),
                $message
            );
            return;
        }

        $this->assertSame($expected, $actual, $message);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::normalize($v);
        }
        if ($isAssoc) {
            ksort($out);
        }
        return $out;
    }
}
