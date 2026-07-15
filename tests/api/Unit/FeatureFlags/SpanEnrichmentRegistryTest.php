<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\SpanEnrichmentAccumulator;
use DDTrace\FeatureFlags\SpanEnrichmentBinder;
use DDTrace\FeatureFlags\SpanEnrichmentRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the PR-review blocker: the native staging API
 * (DDTrace\Internal\set_ffe_span_enrichment_tags) REPLACES the request-global
 * tag slots on every call, so independent per-binder / per-provider
 * accumulators would OVERWRITE rather than aggregate when multiple evaluation
 * paths run under one root span. The fix routes ALL PHP evaluation paths
 * through one shared request-scoped SpanEnrichmentRegistry; these tests assert
 * the union semantics and the centralized lifecycle.
 *
 * These run without the native extension: the registry's root-span lifecycle is
 * driven through injectable seams (setRootSpanSeams), and the staged tag set is
 * inspected via stagedTags() (exactly what stage() would push to the native
 * store).
 */
final class SpanEnrichmentRegistryTest extends TestCase
{
    public function testTwoBindersUnderOneRootAggregateRatherThanOverwrite()
    {
        SpanEnrichmentRegistry::reset();
        // Two SpanEnrichmentBinder instances (standing in for two clients / a
        // client + a provider) both accumulate under the SAME simulated root.
        // The pre-fix bug: the second path's stage() would replace the first's
        // serial ids. With the shared registry the staged flags are the UNION.
        $registry = $this->registryBoundToRoot(1);

        $binderA = new SpanEnrichmentBinder();
        $binderB = new SpanEnrichmentBinder();

        $binderA->accumulate('flag-a', $this->detailsWithSerialId(100), null);
        $binderB->accumulate('flag-b', $this->detailsWithSerialId(108), null);

        $decoded = $this->decodeFlags($registry->stagedTags());
        $this->assertSame(array(100, 108), $decoded);
    }

    public function testTwoBindersUnderOneRootAggregateSubjects()
    {
        SpanEnrichmentRegistry::reset();
        $registry = $this->registryBoundToRoot(1);

        $binderA = new SpanEnrichmentBinder();
        $binderB = new SpanEnrichmentBinder();

        // do_log true + targeting key => a hashed subject is recorded for each.
        $binderA->accumulate('flag-a', $this->detailsWithSerialId(100, true), 'subject-a');
        $binderB->accumulate('flag-b', $this->detailsWithSerialId(108, true), 'subject-b');

        $tags = $registry->stagedTags();
        $this->assertArrayHasKey(SpanEnrichmentAccumulator::TAG_SUBJECTS, $tags);
        $subjects = json_decode($tags[SpanEnrichmentAccumulator::TAG_SUBJECTS], true);
        $this->assertArrayHasKey(hash('sha256', 'subject-a'), $subjects);
        $this->assertArrayHasKey(hash('sha256', 'subject-b'), $subjects);
    }

    public function testTwoBindersUnderOneRootAggregateRuntimeDefaults()
    {
        SpanEnrichmentRegistry::reset();
        $registry = $this->registryBoundToRoot(1);

        $binderA = new SpanEnrichmentBinder();
        $binderB = new SpanEnrichmentBinder();

        // No serial id + no variant => runtime default.
        $binderA->accumulate('flag-a', $this->detailsRuntimeDefault('value-a'), null);
        $binderB->accumulate('flag-b', $this->detailsRuntimeDefault('value-b'), null);

        $defaults = json_decode(
            $registry->stagedTags()[SpanEnrichmentAccumulator::TAG_RUNTIME_DEFAULTS],
            true
        );
        $this->assertSame('value-a', $defaults['flag-a']);
        $this->assertSame('value-b', $defaults['flag-b']);
    }

    public function testNewRootDoesNotInheritPreviousRootIds()
    {
        // CR-01: crossing a root-span boundary resets the shared accumulator so
        // the next root carries only its own evaluations.
        SpanEnrichmentRegistry::reset();
        $rootId = 1;
        $registry = SpanEnrichmentRegistry::instance();
        $registry->setRootSpanSeams(function () use (&$rootId) {
            return $rootId;
        }, function ($id, $reset) {
            // no-op scheduler: we drive the boundary manually below.
        });

        $binder = new SpanEnrichmentBinder();
        $binder->accumulate('flag-a', $this->detailsWithSerialId(100), null);
        $this->assertSame(array(100), $this->decodeFlags($registry->stagedTags()));

        // Enter a new root span; the next accumulation must not see id 100.
        $rootId = 2;
        $binder->accumulate('flag-b', $this->detailsWithSerialId(200), null);
        $this->assertSame(array(200), $this->decodeFlags($registry->stagedTags()));
    }

    public function testRootCloseSchedulerIsBoundAtMostOncePerRootAcrossManyBinders()
    {
        // The lifecycle fix: a long-lived root with many short-lived binders must
        // register AT MOST ONE close reset (not one per binder), so binders/
        // accumulators are not retained per instance.
        SpanEnrichmentRegistry::reset();
        $registry = SpanEnrichmentRegistry::instance();
        $scheduleCount = 0;
        $registry->setRootSpanSeams(function () {
            return 7;
        }, function ($id, $reset) use (&$scheduleCount) {
            $scheduleCount++;
        });

        for ($i = 0; $i < 5; $i++) {
            $binder = new SpanEnrichmentBinder();
            $binder->accumulate('flag-' . $i, $this->detailsWithSerialId(10 + $i), null);
        }

        $this->assertSame(1, $scheduleCount);
    }

    public function testRootCloseResetClearsSharedAccumulator()
    {
        SpanEnrichmentRegistry::reset();
        $registry = SpanEnrichmentRegistry::instance();
        $captured = array();
        $registry->setRootSpanSeams(function () {
            return 3;
        }, function ($id, $reset) use (&$captured) {
            $captured[] = $reset;
        });

        $binder = new SpanEnrichmentBinder();
        $binder->accumulate('flag', $this->detailsWithSerialId(42), null);
        $this->assertSame(array(42), $this->decodeFlags($registry->stagedTags()));

        // Fire the one-shot reset the engine would invoke on root close.
        $this->assertCount(1, $captured);
        call_user_func($captured[0]);

        $this->assertSame(array(), $registry->stagedTags());
    }

    /**
     * Regression for the reset-ordering bug (PR review): tracer/span.c fires
     * $root->onClose in REVERSE registration order. If the registry's reset
     * were simply appended, it would run BEFORE an earlier-registered onClose
     * callback that itself evaluates a flag during root close -- wiping the
     * PHP-side bookkeeping mid-close and losing that evaluation from the
     * union. prependOnCloseReset() must guarantee the reset is the LAST
     * onClose callback to fire regardless of registration order.
     */
    public function testPrependOnCloseResetRunsLastUnderReverseIteration()
    {
        $method = new \ReflectionMethod(SpanEnrichmentRegistry::class, 'prependOnCloseReset');
        $method->setAccessible(true);

        $order = array();
        $appCallback = function () use (&$order) {
            $order[] = 'app';
        };
        $reset = function () use (&$order) {
            $order[] = 'reset';
        };

        // Simulate: app code registers its onClose callback BEFORE the first
        // flag evaluation schedules the registry's reset.
        $onClose = array($appCallback);
        $onClose = $method->invoke(null, $onClose, $reset);

        // A later integration registers another callback after ours is bound.
        $laterCallback = function () use (&$order) {
            $order[] = 'later';
        };
        $onClose[] = $laterCallback;

        // Mirror tracer/span.c's ZEND_HASH_REVERSE_FOREACH_VAL: last element first.
        foreach (array_reverse($onClose) as $callback) {
            $callback();
        }

        $this->assertSame(array('later', 'app', 'reset'), $order);
    }

    private function registryBoundToRoot($rootId)
    {
        $registry = SpanEnrichmentRegistry::instance();
        $registry->setRootSpanSeams(function () use ($rootId) {
            return $rootId;
        }, function ($id, $reset) {
            // no-op: tests that need close behaviour capture it explicitly.
        });

        return $registry;
    }

    /**
     * @param array<string, string> $staged
     * @return array<int, int>
     */
    private function decodeFlags(array $staged)
    {
        $flags = isset($staged[SpanEnrichmentAccumulator::TAG_FLAGS])
            ? $staged[SpanEnrichmentAccumulator::TAG_FLAGS]
            : null;
        if ($flags === null) {
            return array();
        }

        return (new SpanEnrichmentAccumulator())->decodeDeltaVarint($flags);
    }

    private function detailsWithSerialId($serialId, $doLog = false)
    {
        return new EvaluationDetails(
            'on',
            EvaluationType::STRING,
            EvaluationReason::TARGETING_MATCH,
            'on',
            null,
            null,
            array(),
            array('serialId' => $serialId, 'doLog' => $doLog),
            array()
        );
    }

    private function detailsRuntimeDefault($value)
    {
        // No serial id, no variant => runtime default (Pattern C).
        return new EvaluationDetails(
            $value,
            EvaluationType::STRING,
            EvaluationReason::DEFAULT_REASON,
            null,
            null,
            null,
            array(),
            array(),
            array()
        );
    }
}
