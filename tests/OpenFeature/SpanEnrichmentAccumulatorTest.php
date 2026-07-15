<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\Evaluator;
use DDTrace\FeatureFlags\SpanEnrichmentAccumulator;
use DDTrace\FeatureFlags\SpanEnrichmentBinder;
use DDTrace\FeatureFlags\SpanEnrichmentRegistry;
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

    /**
     * Regression for the mbstring-free fallback path (PR review: byte vs.
     * character truncation): without ext-mbstring, a naive substr($value, 0,
     * 64) cuts at 64 BYTES, which truncates multi-byte text far below 64
     * characters (e.g. ~21 chars for 3-byte CJK). Invoked directly via
     * Reflection so this test exercises the fallback regardless of whether
     * ext-mbstring happens to be loaded in the environment running the suite.
     *
     * @dataProvider utf8FallbackTruncationCases
     */
    public function testTruncateUtf8ByteFallbackCountsCharactersNotBytes(string $input, int $expectedChars): void
    {
        $acc = new SpanEnrichmentAccumulator();
        $method = new \ReflectionMethod(SpanEnrichmentAccumulator::class, 'truncateUtf8ByteFallback');
        $method->setAccessible(true);

        $truncated = $method->invoke($acc, $input, 64);

        self::assertTrue(mb_check_encoding($truncated, 'UTF-8'), 'fallback must not split a multi-byte sequence');
        self::assertSame($expectedChars, mb_strlen($truncated, 'UTF-8'));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function utf8FallbackTruncationCases(): array
    {
        return [
            'ascii (1 byte/char)'          => [str_repeat('a', 100), 64],
            'CJK (3 bytes/char)'           => [str_repeat("\u{3042}", 100), 64],
            'emoji (4 bytes/char)'         => [str_repeat("\u{1F600}", 100), 64],
            'shorter than limit unchanged' => [str_repeat("\u{3042}", 10), 10],
        ];
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

    /**
     * Runtime-default rendering must match the frozen Node `String(value)`
     * (RESEARCH.md:102): null => "null", booleans => "true"/"false", scalars
     * via string cast, objects via JSON. This locks the scalar/null/bool parity
     * the L2 decode side expects.
     *
     * @dataProvider nodeStringValueRenderings
     * @param mixed $value
     */
    public function testDefaultRenderingMatchesNodeStringValue($value, string $expected): void
    {
        $acc = new SpanEnrichmentAccumulator();
        $acc->addDefault('flag', $value);

        $defaults = json_decode($acc->toSpanTags()[SpanEnrichmentAccumulator::TAG_RUNTIME_DEFAULTS], true);

        self::assertSame($expected, $defaults['flag']);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function nodeStringValueRenderings(): array
    {
        return [
            'null => "null"'        => [null, 'null'],
            'true => "true"'        => [true, 'true'],
            'false => "false"'      => [false, 'false'],
            'int => decimal string' => [42, '42'],
            'float => string'       => [4.5, '4.5'],
            'string passthrough'    => ['plain', 'plain'],
            'stdClass => JSON'      => [(object) ['a' => 1], '{"a":1}'],
        ];
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

    // ---- CR-01 regression: per-root reset (multi-root + cross-request) -----
    //
    // The pre-fix provider only ever ADDED to the per-provider accumulator and
    // re-staged the FULL accumulated set on every resolve(); clear() had no
    // production caller. So after root span 1 closed, span 2 re-staged span 1's
    // data (within-request contamination), and in persistent SAPIs the
    // accumulator (incl. SHA256 subject keys) leaked across requests. These
    // tests drive the provider across simulated root-span boundaries and assert
    // the staged tags reflect ONLY the current root's evaluations.

    public function testSecondRootSpanDoesNotInheritFirstRootSerialIds(): void
    {
        // Within ONE request, two sequential root spans. Span 2 must stage only
        // its own serial id — not span 1's (CR-01 consequence #1).
        $evaluator = new SpanEnrichmentTestEvaluator();
        $evaluator->setResult('flag-a', 'a', 'a', ['serialId' => 11, 'doLog' => false]);
        $evaluator->setResult('flag-b', 'b', 'b', ['serialId' => 22, 'doLog' => false]);
        $provider = $this->multiRootProvider($evaluator);

        // Root span #1.
        $provider->enterRootSpan(1);
        $provider->resolveStringValue('flag-a', 'fallback', new EvaluationContext('user-1'));
        $firstStaged = $provider->lastStagedTags();
        self::assertSame([11], $this->decodeFlags($firstStaged));

        // Root span #1 closes (fires the registered one-shot clear).
        $provider->closeRootSpan(1);

        // Root span #2 — a fresh root in the same request/provider.
        $provider->enterRootSpan(2);
        $provider->resolveStringValue('flag-b', 'fallback', new EvaluationContext('user-2'));
        $secondStaged = $provider->lastStagedTags();

        // BUG (pre-fix): would decode to [11, 22] (span 1's id leaked into span 2).
        self::assertSame([22], $this->decodeFlags($secondStaged));
    }

    public function testSecondRootSpanDoesNotInheritFirstRootSubjects(): void
    {
        // Hashed subject keys must not carry from root #1 to root #2 (privacy
        // dimension of CR-01).
        $evaluator = new SpanEnrichmentTestEvaluator();
        $evaluator->setResult('flag-a', 'a', 'a', ['serialId' => 11, 'doLog' => true]);
        $evaluator->setResult('flag-b', 'b', 'b', ['serialId' => 22, 'doLog' => true]);
        $provider = $this->multiRootProvider($evaluator);

        $provider->enterRootSpan(1);
        $provider->resolveStringValue('flag-a', 'fallback', new EvaluationContext('alice'));
        $provider->closeRootSpan(1);

        $provider->enterRootSpan(2);
        $provider->resolveStringValue('flag-b', 'fallback', new EvaluationContext('bob'));
        $staged = $provider->lastStagedTags();

        $subjects = json_decode($staged[SpanEnrichmentAccumulator::TAG_SUBJECTS] ?? '{}', true);
        // Only bob's hashed key may be present; alice's must be gone.
        self::assertArrayHasKey(hash('sha256', 'bob'), $subjects);
        self::assertArrayNotHasKey(hash('sha256', 'alice'), $subjects);
    }

    public function testSecondRootSpanDoesNotInheritFirstRootDefaults(): void
    {
        // Runtime defaults must also reset per root span.
        $evaluator = new SpanEnrichmentTestEvaluator();
        $evaluator->setResult('flag-a', 'default-a', null, []); // missing variant => default
        $evaluator->setResult('flag-b', 'default-b', null, []);
        $provider = $this->multiRootProvider($evaluator);

        $provider->enterRootSpan(1);
        $provider->resolveStringValue('flag-a', 'fallback', new EvaluationContext('user-1'));
        $provider->closeRootSpan(1);

        $provider->enterRootSpan(2);
        $provider->resolveStringValue('flag-b', 'fallback', new EvaluationContext('user-2'));
        $staged = $provider->lastStagedTags();

        $defaults = json_decode($staged[SpanEnrichmentAccumulator::TAG_RUNTIME_DEFAULTS] ?? '{}', true);
        self::assertSame(['flag-b' => 'default-b'], $defaults);
    }

    public function testNewRootResetsEvenWhenPreviousRootWasNeverClosed(): void
    {
        // Defensive path: a dropped/abandoned root never fires onClose, so the
        // provider must still reset when it observes a NEW root id on the next
        // evaluation (otherwise a dropped root's data leaks into the next root /
        // next request).
        $evaluator = new SpanEnrichmentTestEvaluator();
        $evaluator->setResult('flag-a', 'a', 'a', ['serialId' => 11, 'doLog' => false]);
        $evaluator->setResult('flag-b', 'b', 'b', ['serialId' => 22, 'doLog' => false]);
        $provider = $this->multiRootProvider($evaluator);

        $provider->enterRootSpan(1);
        $provider->resolveStringValue('flag-a', 'fallback', new EvaluationContext('user-1'));
        // NOTE: no closeRootSpan(1) — the root was dropped/abandoned.

        $provider->enterRootSpan(2);
        $provider->resolveStringValue('flag-b', 'fallback', new EvaluationContext('user-2'));

        self::assertSame([22], $this->decodeFlags($provider->lastStagedTags()));
    }

    public function testCrossRequestDoesNotLeakAccumulatedStateOnSharedProvider(): void
    {
        // Persistent-SAPI model (php-fpm / RoadRunner / Swoole): ONE provider
        // instance serves multiple requests. Each request has its own root span.
        // Request 2 must NOT carry request 1's serial ids or hashed subjects
        // (CR-01 consequence #2 — the cross-request privacy leak).
        $evaluator = new SpanEnrichmentTestEvaluator();
        $evaluator->setResult('flag-a', 'a', 'a', ['serialId' => 11, 'doLog' => true]);
        $evaluator->setResult('flag-b', 'b', 'b', ['serialId' => 22, 'doLog' => true]);
        $provider = $this->multiRootProvider($evaluator);

        // ---- Request 1 ----
        $provider->enterRootSpan(101);
        $provider->resolveStringValue('flag-a', 'fallback', new EvaluationContext('req1-user'));
        $provider->closeRootSpan(101); // request ends: root span closes

        // ---- Request 2 (same provider instance) ----
        $provider->enterRootSpan(202);
        $provider->resolveStringValue('flag-b', 'fallback', new EvaluationContext('req2-user'));
        $staged = $provider->lastStagedTags();

        self::assertSame([22], $this->decodeFlags($staged), 'serial ids leaked across requests');
        $subjects = json_decode($staged[SpanEnrichmentAccumulator::TAG_SUBJECTS] ?? '{}', true);
        self::assertArrayHasKey(hash('sha256', 'req2-user'), $subjects);
        self::assertArrayNotHasKey(
            hash('sha256', 'req1-user'),
            $subjects,
            'request 1 hashed subject key leaked into request 2'
        );
    }

    public function testRootCloseClearsAccumulatorEvenWithNoSubsequentEval(): void
    {
        // After a root span closes, the accumulator must be empty even if no
        // further evaluation happens (lockstep with the native flush; mirrors
        // the Node #onSpanFinish cleanup). Otherwise stale data lingers on the
        // provider until the next eval.
        $accumulator = new SpanEnrichmentAccumulator();
        $evaluator = new SpanEnrichmentTestEvaluator();
        $evaluator->setResult('flag-a', 'a', 'a', ['serialId' => 11, 'doLog' => false]);
        $provider = $this->multiRootProvider($evaluator, $accumulator);

        $provider->enterRootSpan(1);
        $provider->resolveStringValue('flag-a', 'fallback', new EvaluationContext('user-1'));
        self::assertTrue($accumulator->hasData());

        $provider->closeRootSpan(1);

        self::assertFalse($accumulator->hasData(), 'accumulator not cleared on root close');
    }

    /**
     * @param array<string, string> $staged
     * @return array<int, int>
     */
    private function decodeFlags(array $staged): array
    {
        $flags = $staged[SpanEnrichmentAccumulator::TAG_FLAGS] ?? null;
        if ($flags === null) {
            return [];
        }
        return (new SpanEnrichmentAccumulator())->decodeDeltaVarint($flags);
    }

    private function multiRootProvider(
        Evaluator $evaluator,
        ?SpanEnrichmentAccumulator $accumulator = null
    ): MultiRootSpanEnrichmentProvider {
        $accumulator = $accumulator ?? new SpanEnrichmentAccumulator();
        $provider = DataDogProvider::createWithDependencies(
            $evaluator,
            new NullLogger(LogLevel::EMERGENCY)
        );
        // Force-enable enrichment (give the provider a binder) and route the
        // shared registry at the injected accumulator. The lifecycle now lives
        // in the registry, so MultiRootSpanEnrichmentProvider drives the
        // registry's root-span seams rather than the provider's.
        SpanEnrichmentRegistry::reset();
        self::accessibleProperty($provider, 'spanEnrichmentBinder')
            ->setValue($provider, new SpanEnrichmentBinder());
        SpanEnrichmentRegistry::instance()->setAccumulator($accumulator);

        return new MultiRootSpanEnrichmentProvider($provider, $accumulator);
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

    public function testGateOffAllocatesNoBinder(): void
    {
        // With the gate off the provider must construct NO SpanEnrichmentBinder
        // at all (DG-005 zero-idle; PR-review should-fix). We assert that via
        // reflection on a default-built provider; in this unit context
        // dd_trace_env_config is unavailable, so the gate reads as off.
        $provider = new DataDogProvider(new NullLogger(LogLevel::EMERGENCY));

        $binderProp = self::accessibleProperty($provider, 'spanEnrichmentBinder');

        self::assertNull($binderProp->getValue($provider));
    }

    public function testGateOffResolveProducesNoSpanTags(): void
    {
        SpanEnrichmentRegistry::reset();
        $evaluator = new SpanEnrichmentTestEvaluator();
        $evaluator->setResult('flag', 'on', 'on', ['serialId' => 99, 'doLog' => true]);
        // Gate off: createWithDependencies leaves spanEnrichmentBinder null.
        $provider = DataDogProvider::createWithDependencies(
            $evaluator,
            new NullLogger(LogLevel::EMERGENCY)
        );

        $provider->resolveStringValue('flag', 'fallback', new EvaluationContext('user-123'));

        // No binder was constructed and nothing was accumulated into the shared
        // registry.
        $binderProp = self::accessibleProperty($provider, 'spanEnrichmentBinder');
        self::assertNull($binderProp->getValue($provider));
        self::assertSame([], SpanEnrichmentRegistry::instance()->stagedTags());
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

        // Force-enable the feature by giving the provider a binder (in
        // production the gate read does this), and inject the test accumulator
        // into the SHARED registry that all evaluation paths now feed, so we can
        // inspect the accumulated state without the native extension. No root
        // is simulated here, so the registry's no-root path keeps the injected
        // accumulator (rootId stays null across evaluations).
        SpanEnrichmentRegistry::reset();
        self::accessibleProperty($provider, 'spanEnrichmentBinder')
            ->setValue($provider, new SpanEnrichmentBinder());
        SpanEnrichmentRegistry::instance()->setAccumulator($accumulator);

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

/**
 * Pure-PHP simulator of the native root-span lifecycle so the CR-01 regression
 * tests run without the extension. The per-root accumulation lifecycle now
 * lives in the SHARED SpanEnrichmentRegistry, so this drives the REGISTRY's two
 * root-span seams (setRootSpanSeams) rather than the provider's:
 *  - rootIdResolver: returns the current simulated root id, standing in for the
 *    native peek_root_span_id() / spl_object_id(\DDTrace\root_span()).
 *  - rootCloseScheduler: records the one-shot accumulator reset that the
 *    registry binds to $root->onClose; closeRootSpan() fires it, mirroring
 *    span.c invoking the onClose handlers before the native flush.
 *
 * The provider's real resolve -> binder -> registry accumulate path runs
 * unchanged; the shared accumulator IS the payload stage() pushes to the native
 * store, so the regression tests assert on the registry's staged tags (what
 * would be staged for the current root).
 */
final class MultiRootSpanEnrichmentProvider
{
    /** @var DataDogProvider */
    private $provider;

    /** @var SpanEnrichmentAccumulator */
    private $accumulator;

    /** @var int|null current simulated root span id (null = no active root). */
    private $currentRootId = null;

    /** @var array<int, list<callable>> root id => registered one-shot resets. */
    private $onRootClose = [];

    public function __construct(DataDogProvider $provider, SpanEnrichmentAccumulator $accumulator)
    {
        $this->provider = $provider;
        $this->accumulator = $accumulator;

        SpanEnrichmentRegistry::instance()->setRootSpanSeams(
            function (): ?int {
                return $this->currentRootId;
            },
            function (int $rootId, callable $reset): void {
                $this->onRootClose[$rootId][] = $reset;
            }
        );
    }

    public function enterRootSpan(int $rootId): void
    {
        $this->currentRootId = $rootId;
    }

    public function closeRootSpan(int $rootId): void
    {
        // Fire the resets bound to this root, then drop the active root (the
        // engine empties $root->onClose after invoking it — one-shot).
        $callbacks = $this->onRootClose[$rootId] ?? [];
        unset($this->onRootClose[$rootId]);
        foreach ($callbacks as $callback) {
            $callback();
        }
        if ($this->currentRootId === $rootId) {
            $this->currentRootId = null;
        }
    }

    /**
     * @param mixed ...$args
     */
    public function resolveStringValue(...$args): void
    {
        $this->provider->resolveStringValue(...$args);
    }

    /**
     * The encoded tag set that would be staged for the CURRENT root span — i.e.
     * exactly what the shared registry's stage() pushes into the native store.
     *
     * @return array<string, string>
     */
    public function lastStagedTags(): array
    {
        return $this->accumulator->toSpanTags();
    }

    public function accumulator(): SpanEnrichmentAccumulator
    {
        return $this->accumulator;
    }
}
