<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\Evaluator;
use DDTrace\FeatureFlags\SpanEnrichmentAccumulator;
use DDTrace\Log\LogLevel;
use DDTrace\Log\NullLogger;
use DDTrace\OpenFeature\DataDogProvider;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use PHPUnit\Framework\TestCase;

/**
 * L0 suite for the PHP APM feature-flag span enrichment (PHP-01).
 *
 * These tests exercise the frozen contract logic that does NOT require the
 * native extension: the codec, the accumulator limits/shapes, and the DG-004
 * inline accumulation in DataDogProvider::resolve(). The native close-span
 * write (ffe_* onto root meta) and the C-bridge serial_id passthrough are
 * covered by tests/ext/ffe/serial_id_passthrough.phpt against the built
 * extension.
 */
final class SpanEnrichmentAccumulatorTest extends TestCase
{
    // ---- Case 7: codec golden-vector round-trip --------------------------

    public function testGoldenVectorCodec(): void
    {
        $acc = new SpanEnrichmentAccumulator();
        $acc->addSerialId(100);
        $acc->addSerialId(108);
        $acc->addSerialId(128);
        $acc->addSerialId(130);

        $tags = $acc->toSpanTags();

        // [100,108,128,130] -> deltas [100,8,20,2] -> ULEB128 [0x64,0x08,0x14,0x02]
        // -> base64 "ZAgUAg==". This is the frozen oracle shared with the L2
        // decode side; any divergence breaks backend/Trino parity.
        self::assertSame('ZAgUAg==', $tags[SpanEnrichmentAccumulator::TAG_FLAGS]);
    }

    public function testCodecRoundTrips(): void
    {
        $acc = new SpanEnrichmentAccumulator();
        $input = [130, 100, 108, 128, 100]; // out of order + a duplicate
        foreach ($input as $id) {
            $acc->addSerialId($id);
        }

        $encoded = $acc->toSpanTags()[SpanEnrichmentAccumulator::TAG_FLAGS];
        $decoded = $acc->decodeDeltaVarint($encoded);

        self::assertSame([100, 108, 128, 130], $decoded);
    }

    public function testCodecMultiByteVarint(): void
    {
        // 300 needs two ULEB128 bytes: delta 300 = 0b100101100 ->
        // [0xAC, 0x02]. Round-tripping proves the continuation-bit handling.
        $acc = new SpanEnrichmentAccumulator();
        $acc->addSerialId(300);
        $acc->addSerialId(301);

        $decoded = $acc->decodeDeltaVarint(
            $acc->toSpanTags()[SpanEnrichmentAccumulator::TAG_FLAGS]
        );

        self::assertSame([300, 301], $decoded);
    }

    public function testEmptySerialIdsOmitsFlagsTag(): void
    {
        $acc = new SpanEnrichmentAccumulator();

        self::assertFalse($acc->hasData());
        self::assertArrayNotHasKey(SpanEnrichmentAccumulator::TAG_FLAGS, $acc->toSpanTags());
    }

    // ---- dedupe + sort ----------------------------------------------------

    public function testSerialIdsAreDedupedAndSorted(): void
    {
        $acc = new SpanEnrichmentAccumulator();
        foreach ([5, 1, 5, 3, 1] as $id) {
            $acc->addSerialId($id);
        }

        $decoded = $acc->decodeDeltaVarint(
            $acc->toSpanTags()[SpanEnrichmentAccumulator::TAG_FLAGS]
        );

        self::assertSame([1, 3, 5], $decoded);
    }

    // ---- Case 4: limits ---------------------------------------------------

    public function testMax200SerialIdsEnforced(): void
    {
        $acc = new SpanEnrichmentAccumulator();
        for ($i = 1; $i <= 250; $i++) {
            $acc->addSerialId($i);
        }

        $decoded = $acc->decodeDeltaVarint(
            $acc->toSpanTags()[SpanEnrichmentAccumulator::TAG_FLAGS]
        );

        self::assertCount(200, $decoded);
        self::assertSame(1, $decoded[0]);
        self::assertSame(200, $decoded[199]);
    }

    public function testMax10SubjectsEnforced(): void
    {
        $acc = new SpanEnrichmentAccumulator();
        for ($i = 1; $i <= 15; $i++) {
            $acc->addSubject('subject-' . $i, $i);
        }

        $subjects = json_decode($acc->toSpanTags()[SpanEnrichmentAccumulator::TAG_SUBJECTS], true);

        self::assertCount(10, $subjects);
    }

    public function testMax20ExperimentsPerSubjectEnforced(): void
    {
        $acc = new SpanEnrichmentAccumulator();
        $acc->addSerialId(1); // ensure hasData()
        for ($i = 1; $i <= 25; $i++) {
            $acc->addSubject('user-123', $i);
        }

        $subjects = json_decode($acc->toSpanTags()[SpanEnrichmentAccumulator::TAG_SUBJECTS], true);
        $hashed = hash('sha256', 'user-123');
        $decoded = $acc->decodeDeltaVarint($subjects[$hashed]);

        self::assertCount(20, $decoded);
    }

    public function testMax5DefaultsEnforced(): void
    {
        $acc = new SpanEnrichmentAccumulator();
        for ($i = 1; $i <= 8; $i++) {
            $acc->addDefault('flag-' . $i, 'value-' . $i);
        }

        $defaults = json_decode($acc->toSpanTags()[SpanEnrichmentAccumulator::TAG_RUNTIME_DEFAULTS], true);

        self::assertCount(5, $defaults);
    }

    public function testDefaultIsFirstWins(): void
    {
        $acc = new SpanEnrichmentAccumulator();
        $acc->addDefault('flag', 'first');
        $acc->addDefault('flag', 'second');

        $defaults = json_decode($acc->toSpanTags()[SpanEnrichmentAccumulator::TAG_RUNTIME_DEFAULTS], true);

        self::assertSame('first', $defaults['flag']);
    }

    public function testDefaultValueTruncatedTo64Chars(): void
    {
        $acc = new SpanEnrichmentAccumulator();
        $acc->addDefault('flag', str_repeat('a', 100));

        $defaults = json_decode($acc->toSpanTags()[SpanEnrichmentAccumulator::TAG_RUNTIME_DEFAULTS], true);

        self::assertSame(64, strlen($defaults['flag']));
    }

    public function testDefaultValueTruncationIsUtf8Safe(): void
    {
        // 80 two-byte characters (160 bytes) — exceeds the 64-CHARACTER budget.
        // A naive 64-byte cut would split a multi-byte char and corrupt the
        // string; the UTF-8-safe truncation keeps exactly 64 whole characters.
        $acc = new SpanEnrichmentAccumulator();
        $acc->addDefault('flag', str_repeat("\u{00e9}", 80));

        $defaults = json_decode($acc->toSpanTags()[SpanEnrichmentAccumulator::TAG_RUNTIME_DEFAULTS], true);

        // Valid UTF-8 (json_decode would have returned null on a broken string).
        self::assertNotNull($defaults);
        self::assertSame(64, mb_strlen($defaults['flag'], 'UTF-8'));
    }

    // ---- Case 5: JSON / object default ------------------------------------

    public function testObjectDefaultIsJsonStringified(): void
    {
        $acc = new SpanEnrichmentAccumulator();
        $acc->addDefault('flag', ['enabled' => true, 'n' => 3]);

        $defaults = json_decode($acc->toSpanTags()[SpanEnrichmentAccumulator::TAG_RUNTIME_DEFAULTS], true);

        // Must be JSON, never "Array"/"[object Object]".
        self::assertSame('{"enabled":true,"n":3}', $defaults['flag']);
    }

    public function testSubjectsTagIsJsonObjectOfBase64(): void
    {
        $acc = new SpanEnrichmentAccumulator();
        $acc->addSerialId(100);
        $acc->addSubject('user-123', 100);

        $raw = $acc->toSpanTags()[SpanEnrichmentAccumulator::TAG_SUBJECTS];
        $subjects = json_decode($raw, true);

        self::assertIsArray($subjects);
        $hashed = hash('sha256', 'user-123');
        // SHA256("user-123") is the frozen fixture digest.
        self::assertSame(
            'fcdec6df4d44dbc637c7c5b58efface52a7f8a88535423430255be0bb89bedd8',
            $hashed
        );
        self::assertArrayHasKey($hashed, $subjects);
        self::assertSame([100], $acc->decodeDeltaVarint($subjects[$hashed]));
    }

    public function testFlagsTagIsBareBase64NotJson(): void
    {
        $acc = new SpanEnrichmentAccumulator();
        $acc->addSerialId(100);

        $flags = $acc->toSpanTags()[SpanEnrichmentAccumulator::TAG_FLAGS];

        // Bare base64 string, NOT a JSON-wrapped value.
        self::assertSame('"', substr(json_encode($flags), 0, 1));
        self::assertNull(json_decode($flags, true));
        self::assertSame($flags, base64_encode(base64_decode($flags, true)));
    }

    public function testClearResetsState(): void
    {
        $acc = new SpanEnrichmentAccumulator();
        $acc->addSerialId(1);
        $acc->addDefault('flag', 'value');
        $acc->addSubject('user', 1);

        $acc->clear();

        self::assertFalse($acc->hasData());
        self::assertSame([], $acc->toSpanTags());
    }

    // ---- DG-004 inline accumulation via DataDogProvider ------------------

    public function testInlineAccumulationRecordsSerialIdAndSubject(): void
    {
        $accumulator = new SpanEnrichmentAccumulator();
        $provider = $this->providerWithAccumulator($accumulator);

        $provider->resolveStringValue('flag', 'fallback', new EvaluationContext('user-123'));

        self::assertTrue($accumulator->hasData());
        $tags = $accumulator->toSpanTags();
        self::assertSame([4242], $accumulator->decodeDeltaVarint($tags[SpanEnrichmentAccumulator::TAG_FLAGS]));
        // do_log was true and a targeting key was present -> a subject is added.
        $subjects = json_decode($tags[SpanEnrichmentAccumulator::TAG_SUBJECTS], true);
        self::assertArrayHasKey(hash('sha256', 'user-123'), $subjects);
    }

    public function testInlineAccumulationSkipsSubjectWhenDoLogFalse(): void
    {
        $accumulator = new SpanEnrichmentAccumulator();
        $evaluator = new SpanEnrichmentTestEvaluator();
        $evaluator->setResult('flag', 'on', 'on', ['serialId' => 7, 'doLog' => false]);
        $provider = $this->providerWithAccumulator($accumulator, $evaluator);

        $provider->resolveStringValue('flag', 'fallback', new EvaluationContext('user-123'));

        $tags = $accumulator->toSpanTags();
        self::assertArrayHasKey(SpanEnrichmentAccumulator::TAG_FLAGS, $tags);
        self::assertArrayNotHasKey(SpanEnrichmentAccumulator::TAG_SUBJECTS, $tags);
    }

    // ---- Case 3: error / default variant = runtime default ---------------

    public function testInlineAccumulationRuntimeDefaultOnMissingVariant(): void
    {
        $accumulator = new SpanEnrichmentAccumulator();
        $evaluator = new SpanEnrichmentTestEvaluator();
        // No serialId, no variant -> runtime default (Pattern C).
        $evaluator->setResult('flag', 'computed-default', null, []);
        $provider = $this->providerWithAccumulator($accumulator, $evaluator);

        $provider->resolveStringValue('flag', 'fallback', new EvaluationContext('user-123'));

        $defaults = json_decode(
            $accumulator->toSpanTags()[SpanEnrichmentAccumulator::TAG_RUNTIME_DEFAULTS],
            true
        );
        self::assertSame('computed-default', $defaults['flag']);
    }

    public function testInlineAccumulationDoesNotRaiseWithoutTargetingKey(): void
    {
        // Case "no-span"/no-context analog: accumulation must not throw when
        // there is no targeting key. The serial id is still recorded; no subject.
        $accumulator = new SpanEnrichmentAccumulator();
        $provider = $this->providerWithAccumulator($accumulator);

        $provider->resolveStringValue('flag', 'fallback');

        $tags = $accumulator->toSpanTags();
        self::assertArrayHasKey(SpanEnrichmentAccumulator::TAG_FLAGS, $tags);
        self::assertArrayNotHasKey(SpanEnrichmentAccumulator::TAG_SUBJECTS, $tags);
    }

    // ---- Case 6: gate-off negative control (DG-005) ----------------------

    public function testGateOffAllocatesNoAccumulatorAndStagesNothing(): void
    {
        // With the gate off the provider must construct NO accumulator at all
        // (DG-005 zero-idle). We assert that via reflection on a default-built
        // provider; in this unit context dd_trace_env_config is unavailable, so
        // the gate reads as off.
        $provider = new DataDogProvider(new NullLogger(LogLevel::EMERGENCY));

        $enabledProp = self::accessibleProperty($provider, 'spanEnrichmentEnabled');
        $accProp = self::accessibleProperty($provider, 'spanEnrichmentAccumulator');

        self::assertFalse($enabledProp->getValue($provider));
        self::assertNull($accProp->getValue($provider));
    }

    public function testGateOffResolveProducesNoSpanTags(): void
    {
        $evaluator = new SpanEnrichmentTestEvaluator();
        $evaluator->setResult('flag', 'on', 'on', ['serialId' => 99, 'doLog' => true]);
        // Gate off: createWithDependencies leaves spanEnrichmentEnabled false.
        $provider = DataDogProvider::createWithDependencies(
            $evaluator,
            new NullLogger(LogLevel::EMERGENCY)
        );

        $provider->resolveStringValue('flag', 'fallback', new EvaluationContext('user-123'));

        $accProp = self::accessibleProperty($provider, 'spanEnrichmentAccumulator');

        // No accumulator was constructed and nothing was accumulated.
        self::assertNull($accProp->getValue($provider));
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * Make a private property accessible across PHP versions without emitting
     * the PHP 8.1+ "setAccessible has no effect" deprecation notice (which would
     * mark the test risky). On <8.1 setAccessible(true) is still required.
     */
    private static function accessibleProperty(object $object, string $name): \ReflectionProperty
    {
        $property = (new \ReflectionObject($object))->getProperty($name);
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        return $property;
    }

    private function providerWithAccumulator(
        SpanEnrichmentAccumulator $accumulator,
        ?Evaluator $evaluator = null
    ): DataDogProvider {
        if ($evaluator === null) {
            $evaluator = new SpanEnrichmentTestEvaluator();
            $evaluator->setResult('flag', 'on', 'on', ['serialId' => 4242, 'doLog' => true]);
        }

        $provider = DataDogProvider::createWithDependencies(
            $evaluator,
            new NullLogger(LogLevel::EMERGENCY)
        );

        // Force-enable the feature and inject a test accumulator so we can
        // inspect the accumulated state without the native extension.
        self::accessibleProperty($provider, 'spanEnrichmentEnabled')->setValue($provider, true);
        self::accessibleProperty($provider, 'spanEnrichmentAccumulator')->setValue($provider, $accumulator);

        return $provider;
    }
}

/**
 * Minimal Evaluator that returns canned EvaluationDetails (with exposureData
 * carrying serialId/doLog) so the provider's inline accumulation can be tested
 * without the native bridge.
 */
final class SpanEnrichmentTestEvaluator implements Evaluator
{
    /** @var array<string, EvaluationDetails> */
    private array $results = [];

    /**
     * @param mixed $value
     * @param array<string, mixed> $exposureData
     */
    public function setResult(string $flagKey, $value, ?string $variant, array $exposureData): self
    {
        $this->results[$flagKey] = new EvaluationDetails(
            $value,
            $this->typeForValue($value),
            EvaluationReason::TARGETING_MATCH,
            $variant,
            null,
            null,
            [],
            $exposureData,
            []
        );

        return $this;
    }

    public function evaluate($flagKey, $expectedType, $defaultValue, $targetingKey = null, array $attributes = [])
    {
        if (isset($this->results[$flagKey])) {
            return $this->results[$flagKey];
        }

        return new EvaluationDetails(
            $defaultValue,
            EvaluationType::STRING,
            EvaluationReason::DEFAULT_REASON
        );
    }

    /**
     * @param mixed $value
     */
    private function typeForValue($value): string
    {
        if (is_bool($value)) {
            return EvaluationType::BOOLEAN;
        }
        if (is_int($value)) {
            return EvaluationType::INTEGER;
        }
        if (is_float($value)) {
            return EvaluationType::FLOAT;
        }
        if (is_array($value)) {
            return EvaluationType::OBJECT;
        }
        return EvaluationType::STRING;
    }
}
