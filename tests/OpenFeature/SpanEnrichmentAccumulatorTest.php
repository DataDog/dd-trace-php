<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature;

use DDTrace\FeatureFlags\SpanEnrichmentAccumulator;
use PHPUnit\Framework\TestCase;

/**
 * Unit suite for the frozen APM feature-flag span-enrichment codec.
 *
 * These tests exercise only the SpanEnrichmentAccumulator contract logic that
 * does NOT require the native extension: the delta-varint/base64 codec, the
 * limits/dedupe rules, runtime-default stringification, and tag shapes.
 *
 * The end-to-end recording path (SpanEnrichmentRegistry::record() attaching the
 * accumulator to the root span and writing ffe_* onto the root meta) and the
 * C-bridge serial_id passthrough are covered by the .phpt ext tests against the
 * built extension.
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
            // PHP's plain (string) cast diverges from Node's String(number) for
            // these: uppercase "E" exponents, a "-0" for negative zero, and (via
            // (string)'s default precision) a differently-rounded mantissa.
            'float exponent lowercase e' => [1.0e-7, '1e-7'],
            'float exponent with sign'   => [1.5e21, '1.5e+21'],
            'negative zero => "0"'       => [-0.0, '0'],
            'whole float => no decimal'  => [100.0, '100'],
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

}
