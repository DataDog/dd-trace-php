<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature;

use DDTrace\OpenFeature\BridgeResultMapper;
use DDTrace\OpenFeature\EvaluationContextNormalizer;
use DDTrace\OpenFeature\ExposureContext;
use DDTrace\OpenFeature\ExposureWriter;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Reason;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests pinning specific PR #3630 review fixes from Phases 1-3.
 *
 * Each test below pins a specific reviewer directive so that future refactors
 * cannot silently regress a review fix. Every test has a reviewer attribution
 * comment linking to the source of the directive.
 *
 * See .planning/phases/04-observability-and-test-integration/04-PR-REVIEW-AUDIT.md
 * for the full audit table (reviewer, severity, status, original comment) and
 * .planning/phases/04-observability-and-test-integration/04-02-PLAN.md for the
 * full fix catalog this test file pins.
 *
 * Coverage:
 *   Fix 1 - null targeting key preservation (dd-oleksii)
 *   Fix 2 - ERROR reason forced when error_code != 0 (EVAL-06 / Phase 1 D-03)
 *   Fix 3 - do_log=false hard gate suppresses ExposureContext (Phase 2 D-04 / CONF-04)
 *   Fix 4 - primitive-only attribute filtering (bwoebi)
 *   Fix 5 - empty flat attributes JSON-encode to {} not [] (Phase 3 (object) cast)
 *   Fix 6 - dedup triple (flag, allocation, targeting) passed to sidecar (Phase 3 D-11)
 *   Fix 7 - ProviderLifecycle timeout (delegated to ProviderLifecycleTest.php per D-07)
 */
final class RegressionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Fix 1 - null targeting key preservation (dd-oleksii directive)
    //
    // Source: src/OpenFeature/EvaluationContextNormalizer.php::normalize()
    // Reviewer: dd-oleksii, PR #3630 review comment: "null targeting key must be
    // preserved; coercing to empty string breaks downstream allocation matching".
    // See 04-PR-REVIEW-AUDIT.md.
    // -------------------------------------------------------------------------

    public function testNullTargetingKeyIsPreservedNotCoercedToEmptyString(): void
    {
        $normalizer = new EvaluationContextNormalizer();

        $context = new EvaluationContext(null, new Attributes(['plan' => 'enterprise']));

        [$targetingKey, $attributes] = $normalizer->normalize($context);

        $this->assertNull($targetingKey, 'Null targeting key must remain null after normalize');
        $this->assertNotSame('', $targetingKey, 'Null targeting key must not be coerced to empty string');
        $this->assertSame(['plan' => 'enterprise'], $attributes);
    }

    // -------------------------------------------------------------------------
    // Fix 2 - ERROR reason forced when error_code != 0 (Phase 1 D-03 / EVAL-06)
    //
    // Source: src/OpenFeature/BridgeResultMapper.php::mapResult(), lines 155-163.
    // Rationale: even if the bridge returns reason=TARGETING_MATCH alongside a
    // non-zero error_code (possible if Rust and PHP drift), PHP must force
    // Reason::ERROR to satisfy OpenFeature spec (error results MUST use ERROR).
    // See 04-PR-REVIEW-AUDIT.md for EVAL-06 row.
    // -------------------------------------------------------------------------

    public function testErrorReasonForcedWhenErrorCodeNonZeroEvenIfBridgeReportsOtherReason(): void
    {
        $mapper = new BridgeResultMapper();

        $bridgeResult = [
            'value_json' => '"hello"',
            'variant' => 'x',
            'allocation_key' => 'y',
            'reason' => 1, // REASON_TARGETING_MATCH - conflicting on purpose
            'error_code' => 1, // FLAG_NOT_FOUND
            'do_log' => true,
        ];

        $details = $mapper->mapString($bridgeResult, 'default');

        $this->assertSame(Reason::ERROR, $details->getReason(), 'Non-zero error_code must force Reason::ERROR');
        $this->assertSame('default', $details->getValue(), 'Error path returns caller default');
        $this->assertNotNull($details->getError(), 'Error path must carry a ResolutionError');
        $this->assertEquals(
            ErrorCode::FLAG_NOT_FOUND(),
            $details->getError()->getResolutionErrorCode(),
            'error_code=1 maps to ErrorCode::FLAG_NOT_FOUND',
        );
    }

    // -------------------------------------------------------------------------
    // Fix 3 - do_log=false hard gate suppresses ExposureContext (Phase 2 D-04 / CONF-04)
    //
    // Source: src/OpenFeature/ExposureContext.php::fromBridgeResult(), lines 71-76.
    // Rationale: do_log=false is a hard gate. The bridge tells us not to log this
    // evaluation, so no ExposureContext must be produced, regardless of any other
    // field values being valid.
    // See 04-PR-REVIEW-AUDIT.md for Phase 2 D-04 row.
    // -------------------------------------------------------------------------

    public function testDoLogFalseHardGateReturnsNullExposureContext(): void
    {
        $bridgeResult = [
            'value_json' => '"hello"',
            'variant' => 'b',
            'allocation_key' => 'a',
            'reason' => 1,
            'error_code' => 0,
            'do_log' => false,
        ];

        $ctx = ExposureContext::fromBridgeResult(
            $bridgeResult,
            'flag-1',
            'user-1',
            fn (string $name): ?string => null,
        );

        $this->assertNull($ctx, 'do_log=false must produce no ExposureContext (hard gate)');
    }

    // -------------------------------------------------------------------------
    // Fix 4 - primitive-only attribute filtering (bwoebi reviewer directive)
    //
    // Source: src/OpenFeature/EvaluationContextNormalizer.php::filterPrimitiveAttributes().
    // Reviewer: bwoebi, PR #3630 review comment: "the Rust side only accepts flat
    // primitives; filter nested arrays/objects/nulls at the PHP boundary so the
    // C extension doesn't have to defend against bad inputs".
    // See 04-PR-REVIEW-AUDIT.md.
    // -------------------------------------------------------------------------

    public function testPrimitiveOnlyAttributeFilteringDropsNestedArraysAndObjects(): void
    {
        $normalizer = new EvaluationContextNormalizer();

        $context = new EvaluationContext(
            'user-1',
            new Attributes([
                'ok_string' => 'a',
                'ok_int' => 1,
                'ok_float' => 1.5,
                'ok_bool' => true,
                'bad_array' => ['nested'],
                'bad_null' => null,
                'bad_object' => new \stdClass(),
            ]),
        );

        [$targetingKey, $attributes] = $normalizer->normalize($context);

        $this->assertSame('user-1', $targetingKey);

        // Only primitive-typed keys survive; all non-primitives are filtered out
        ksort($attributes);
        $expected = [
            'ok_bool' => true,
            'ok_float' => 1.5,
            'ok_int' => 1,
            'ok_string' => 'a',
        ];
        $this->assertSame($expected, $attributes, 'Only flat primitives survive the normalizer');

        // Explicit assertions the bad keys are gone
        $this->assertArrayNotHasKey('bad_array', $attributes);
        $this->assertArrayNotHasKey('bad_null', $attributes);
        $this->assertArrayNotHasKey('bad_object', $attributes);
    }

    // -------------------------------------------------------------------------
    // Fix 5 - empty flat attributes produce JSON {} not [] (Phase 3 (object) cast)
    //
    // Source: src/OpenFeature/ExposureWriter.php::buildEventJson(), line 123:
    //     'attributes' => (object)$flatAttributes,
    // Rationale: PHP empty arrays JSON-encode to [], but exposure events must
    // carry {"attributes":{}} to match the Ruby/Python cross-tracer format.
    // See 04-PR-REVIEW-AUDIT.md.
    // -------------------------------------------------------------------------

    public function testEmptyAttributesProduceJsonObjectNotArray(): void
    {
        $capturedJson = null;

        $writer = new ExposureWriter(
            sidecarCallable: static function (string $eventJson) use (&$capturedJson): bool {
                $capturedJson = $eventJson;
                return true;
            },
            timestampProvider: static fn (): int => 1234567890,
        );

        $context = new ExposureContext(
            service: null,
            env: null,
            version: null,
            flagKey: 'flag-1',
            allocationKey: 'alloc-1',
            variant: 'variant-a',
            targetingKey: 'user-1',
        );

        // Send with no evaluation attributes so the flat attributes map is empty
        $writer->send($context, []);

        $this->assertNotNull($capturedJson, 'Sidecar callable must have been invoked');
        $this->assertStringContainsString('"attributes":{}', $capturedJson, 'Empty attributes must JSON-encode to {}');
        $this->assertStringNotContainsString('"attributes":[]', $capturedJson, 'Empty attributes must not JSON-encode to []');
    }

    // -------------------------------------------------------------------------
    // Fix 6 - dedup triple passed to sidecar callable (Phase 3 D-11)
    //
    // Source: src/OpenFeature/ExposureWriter.php::send().
    // Rationale: The Rust LRU dedup keys on (flag_key, allocation_key, targeting_key).
    // PHP must faithfully pass all three plus the variant so the sidecar can
    // dedup correctly. This test pins the argument order and values.
    // See 04-PR-REVIEW-AUDIT.md for EXPO regression entry.
    // -------------------------------------------------------------------------

    public function testExposureDedupTripleIsPassedToSidecarCallable(): void
    {
        $capturedArgs = null;

        $writer = new ExposureWriter(
            sidecarCallable: static function (
                string $eventJson,
                string $flagKey,
                string $allocationKey,
                ?string $targetingKey,
                string $variantKey,
            ) use (&$capturedArgs): bool {
                $capturedArgs = [
                    'eventJson' => $eventJson,
                    'flagKey' => $flagKey,
                    'allocationKey' => $allocationKey,
                    'targetingKey' => $targetingKey,
                    'variantKey' => $variantKey,
                ];
                return true;
            },
        );

        $context = new ExposureContext(
            service: null,
            env: null,
            version: null,
            flagKey: 'flag-1',
            allocationKey: 'alloc-1',
            variant: 'v1',
            targetingKey: 'user-1',
        );

        $writer->send($context, null);

        $this->assertNotNull($capturedArgs, 'Sidecar callable must have been invoked');
        $this->assertSame('flag-1', $capturedArgs['flagKey'], 'flagKey must be second arg');
        $this->assertSame('alloc-1', $capturedArgs['allocationKey'], 'allocationKey must be third arg');
        $this->assertSame('user-1', $capturedArgs['targetingKey'], 'targetingKey must be fourth arg');
        $this->assertSame('v1', $capturedArgs['variantKey'], 'variantKey must be fifth arg');
    }

    // -------------------------------------------------------------------------
    // Fix 7 - ProviderLifecycle timeout (PROV-03 regression)
    //
    // Per CONTEXT.md D-07 audit: tests/OpenFeature/ProviderLifecycleTest.php
    // already covers the full timeout matrix (see methods referenced below).
    // Rather than duplicate that coverage here, this delegation test acts as
    // a grep-discoverable breadcrumb linking the regression plan's Fix 7 to
    // the canonical test file.
    //
    // Existing covering tests in ProviderLifecycleTest:
    //   - testBlockingInitSucceedsWhenConfigAvailableImmediately
    //   - testBlockingInitSucceedsWhenConfigArrivesBeforeTimeout
    //   - testBlockingInitTimesOutCorrectly           (primary timeout-returns-false case)
    //   - testBlockingInitWithZeroTimeoutReturnsImmediately
    //   - testSetProviderAndWaitReturnsFalseOnTimeout (end-to-end via compat helper)
    //
    // Source: src/OpenFeature/ProviderLifecycle.php::waitUntilReady().
    // See 04-PR-REVIEW-AUDIT.md for Fix 7 row.
    // -------------------------------------------------------------------------

    public function testLifecycleTimeoutCoverageDelegatedToProviderLifecycleTest(): void
    {
        $providerLifecycleTestPath = __DIR__ . '/ProviderLifecycleTest.php';
        $this->assertTrue(
            file_exists($providerLifecycleTestPath),
            'ProviderLifecycleTest.php must exist to cover waitUntilReady timeout',
        );

        $contents = file_get_contents($providerLifecycleTestPath);
        $this->assertIsString($contents);

        // Assert the specific timeout-covering test methods exist in the canonical file.
        $this->assertStringContainsString(
            'function testBlockingInitTimesOutCorrectly',
            $contents,
            'Primary timeout-returns-false test must live in ProviderLifecycleTest.php',
        );
        $this->assertStringContainsString(
            'function testBlockingInitWithZeroTimeoutReturnsImmediately',
            $contents,
            'Zero-timeout fast-path test must live in ProviderLifecycleTest.php',
        );
        $this->assertStringContainsString(
            'function testBlockingInitSucceedsWhenConfigArrivesBeforeTimeout',
            $contents,
            'Config-arrives-before-timeout test must live in ProviderLifecycleTest.php',
        );
        $this->assertStringContainsString(
            'function testSetProviderAndWaitReturnsFalseOnTimeout',
            $contents,
            'End-to-end compat timeout test must live in ProviderLifecycleTest.php',
        );
    }
}
