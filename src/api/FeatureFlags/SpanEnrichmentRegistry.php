<?php

namespace DDTrace\FeatureFlags;

/**
 * Request-scoped, single shared accumulator + lifecycle for APM feature-flag
 * span enrichment, used by ALL PHP evaluation paths (the native
 * DDTrace\FeatureFlags\Client and the OpenFeature DataDogProvider).
 *
 * Why a single shared registry (PR review blocker, tracer/ffe.c
 * set_ffe_span_enrichment_tags): the native staging API REPLACES the three
 * request-global tag slots on every call. If each Client / each provider owned
 * its own accumulator and staged independently, two clients, two providers, or
 * a mixed OpenFeature + native-client evaluation under the SAME root span would
 * OVERWRITE one another's serial ids / hashed subjects / runtime defaults
 * instead of AGGREGATING them into one root-span payload. Routing every PHP
 * evaluation path through one request-scoped accumulator makes the staged tag
 * set the union of all evaluations seen on the active root span, matching the
 * frozen Node contract.
 *
 * Lifecycle is centralized here (PR review should-fix, per-binder onClose
 * retention): the registry binds AT MOST ONE root-close reset per root span
 * (tracked by $rootCloseBoundRootId), so an arbitrary number of short-lived
 * Client instances under one long-lived root span do not each retain a closure
 * / accumulator. The accumulator is also reset on any root-span boundary
 * transition (CR-01) so a dropped/abandoned root cannot leak into the next root
 * or, in persistent SAPIs, the next request.
 *
 * DG-005: the registry stays fully inert when the experimental span-enrichment
 * gate is off -- the accumulator is allocated lazily on first use and only when
 * the gate is on, so there is no idle per-evaluation or per-span overhead.
 *
 * KNOWN LIMITATION (PR review, not fixed here): this registry tracks exactly
 * ONE active root at a time via a flat $rootId/$accumulator pair, and the
 * native staging bridge (tracer/ffe.c set_ffe_span_enrichment_tags) mirrors
 * that with a single set of request-global slots. When two roots are
 * genuinely open concurrently (fibers, multiple span stacks) and evaluations
 * interleave across them, switching the active root discards the other
 * root's accumulated data, and flushing can attach staged tags to the wrong
 * root. Fixing this properly requires per-root-keyed storage on BOTH sides
 * (PHP accumulators keyed by root id, AND the native slots keyed the same
 * way) -- a native wire-contract-adjacent change, not a PHP-only patch.
 * Deferred as a follow-up given the feature is experimental and gated off by
 * default.
 *
 * PHP 7 compatible (lives in src/api alongside Client / SpanEnrichmentAccumulator
 * and is consumed by the PHP 8-only DataDogProvider).
 */
final class SpanEnrichmentRegistry
{
    const SERIAL_ID_METADATA_KEY = 'serialId';
    const DO_LOG_METADATA_KEY = 'doLog';

    /** @var SpanEnrichmentRegistry|null Request-scoped singleton. */
    private static $instance = null;

    /** @var SpanEnrichmentAccumulator|null Allocated lazily, only when the gate is on. */
    private $accumulator = null;
    /** @var int|null Identity (root span_id) of the root span currently bound. */
    private $rootId = null;
    /**
     * Identity of the root span we have already bound a one-shot close reset to.
     * Ensures AT MOST ONE onClose closure per root span regardless of how many
     * evaluation paths route through the registry under that root.
     *
     * @var int|null
     */
    private $rootCloseBoundRootId = null;
    /** @var callable|null Test seam: resolve the current root-span id. */
    private $rootIdResolver = null;
    /** @var callable|null Test seam: schedule a one-shot reset on root close. */
    private $rootCloseScheduler = null;

    private function __construct()
    {
    }

    /**
     * The request-scoped shared registry. Callers MUST gate-check before using
     * it (the registry itself does no gate read so it stays inert when unused).
     *
     * @return self
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Drop the request-scoped singleton. Test-only seam so each test starts with
     * a clean shared accumulator (in production the registry is recreated per
     * request because PHP statics are reset on request shutdown).
     */
    public static function reset()
    {
        self::$instance = null;
    }

    /**
     * Inject the root-span lifecycle test seams. Null in production, where the
     * registry reads the active root span and binds to its $onClose directly.
     *
     * @param callable|null $rootIdResolver function(): ?int
     * @param callable|null $rootCloseScheduler function(int $rootId, callable $reset): void
     */
    public function setRootSpanSeams($rootIdResolver, $rootCloseScheduler)
    {
        $this->rootIdResolver = $rootIdResolver;
        $this->rootCloseScheduler = $rootCloseScheduler;
    }

    /**
     * Test seam: inject the shared accumulator so a test can inspect the exact
     * state every evaluation path feeds into. In production the accumulator is
     * allocated lazily on first use.
     *
     * @param SpanEnrichmentAccumulator $accumulator
     */
    public function setAccumulator($accumulator)
    {
        $this->accumulator = $accumulator;
    }

    /**
     * Accumulate one evaluation's enrichment into the SHARED accumulator and
     * stage the encoded union for the native close-span write. Mirrors the
     * frozen Node reference branch: a present serial id is recorded (and, when
     * do_log authorizes and a targeting key exists, a hashed subject); an
     * evaluation with no variant is treated as a runtime default. Errors are
     * swallowed -- enrichment must never break flag evaluation.
     *
     * @param string $flagKey
     * @param EvaluationDetails $details
     * @param string|null $targetingKey
     */
    public function accumulate($flagKey, $details, $targetingKey)
    {
        try {
            $this->resetForRootBoundary();

            if ($this->rootId === null) {
                // No active root span: nothing to attach this evaluation to, and
                // staging it now would leak into whichever root opens next.
                return;
            }

            if ($this->accumulator === null) {
                $this->accumulator = new SpanEnrichmentAccumulator();
            }

            $exposure = $details->getExposureData();
            $serialId = is_array($exposure) && array_key_exists(self::SERIAL_ID_METADATA_KEY, $exposure)
                ? $exposure[self::SERIAL_ID_METADATA_KEY]
                : null;
            $doLog = is_array($exposure) && !empty($exposure[self::DO_LOG_METADATA_KEY]);

            if ($serialId !== null) {
                $this->accumulator->addSerialId((int) $serialId);
                if ($doLog && $targetingKey !== null && $targetingKey !== '') {
                    $this->accumulator->addSubject($targetingKey, (int) $serialId);
                }
            } else {
                $variant = $details->getVariant();
                if ($variant === null || $variant === '') {
                    $this->accumulator->addDefault((string) $flagKey, $details->getValue());
                }
            }

            $this->stage();
        } catch (\Throwable $e) {
            // Never let span enrichment break flag evaluation.
        }
    }

    /**
     * Reset the shared accumulator on a root-span boundary (CR-01) so it carries
     * only the active root span's evaluations and never leaks across spans /
     * requests. Fires on ANY transition (new root, or losing the active root) so
     * a dropped/abandoned root -- which never runs its $onClose handler -- cannot
     * leak into the next root or request.
     *
     * KNOWN LIMITATION: this is where the single-active-root assumption bites.
     * If root A is still open when root B becomes active (concurrent roots via
     * fibers / multiple span stacks), this discards A's accumulated data on
     * the A->B transition, and switching back to A later starts it fresh
     * rather than resuming. See the class docblock.
     */
    private function resetForRootBoundary()
    {
        $rootId = $this->currentRootSpanId();
        if ($rootId === $this->rootId) {
            return;
        }

        if ($this->accumulator !== null) {
            $this->accumulator->clear();
        }
        $this->resetStaging();
        $this->rootId = $rootId;

        if ($rootId !== null) {
            $this->scheduleResetOnRootClose($rootId);
        }
    }

    private function stage()
    {
        if ($this->accumulator === null
            || !$this->accumulator->hasData()
            || !\function_exists('DDTrace\\Internal\\set_ffe_span_enrichment_tags')) {
            return;
        }

        $tags = $this->accumulator->toSpanTags();
        \DDTrace\Internal\set_ffe_span_enrichment_tags(
            isset($tags[SpanEnrichmentAccumulator::TAG_FLAGS]) ? $tags[SpanEnrichmentAccumulator::TAG_FLAGS] : null,
            isset($tags[SpanEnrichmentAccumulator::TAG_SUBJECTS]) ? $tags[SpanEnrichmentAccumulator::TAG_SUBJECTS] : null,
            isset($tags[SpanEnrichmentAccumulator::TAG_RUNTIME_DEFAULTS]) ? $tags[SpanEnrichmentAccumulator::TAG_RUNTIME_DEFAULTS] : null
        );
    }

    private function resetStaging()
    {
        if (!\function_exists('DDTrace\\Internal\\set_ffe_span_enrichment_tags')) {
            return;
        }
        \DDTrace\Internal\set_ffe_span_enrichment_tags(null, null, null);
    }

    /**
     * Identity of the active root span (or null). Uses a NON-creating accessor
     * (PR review should-fix, DDTrace\root_span side effect): resolving a root id
     * while merely evaluating a flag must NOT create an autoroot span. The
     * extension exposes DDTrace\Internal\peek_root_span_id() which reads the
     * active root span WITHOUT calling dd_ensure_root_span(); we fall back to
     * the (creating) DDTrace\root_span() only on older extensions that predate
     * the peek helper, preserving behaviour there.
     *
     * peek_root_span_id() returns the root's own span_id, not spl_object_id():
     * object handles are recycled by the engine once a span object is
     * destroyed (e.g. a dropped root), so a handle-based identity could alias a
     * later, unrelated root and make resetForRootBoundary() wrongly skip its
     * reset (PR review, handle-reuse finding). span_id is a random 64-bit value
     * that is never reused within the process, so it does not have this
     * problem. The spl_object_id() fallback below (old extensions without the
     * peek helper) keeps the theoretical handle-reuse exposure, but that path
     * only exists for pre-peek-helper extensions.
     *
     * @return int|null
     */
    private function currentRootSpanId()
    {
        if ($this->rootIdResolver !== null) {
            $id = \call_user_func($this->rootIdResolver);
            return $id === null ? null : (int) $id;
        }

        if (\function_exists('DDTrace\\Internal\\peek_root_span_id')) {
            $id = \DDTrace\Internal\peek_root_span_id();
            return $id === null ? null : (int) $id;
        }

        if (!\function_exists('DDTrace\\root_span')) {
            return null;
        }

        if (!\function_exists('spl_object_id')) {
            return null;
        }

        $root = \DDTrace\root_span();
        return $root !== null ? \spl_object_id($root) : null;
    }

    /**
     * Bind AT MOST ONE one-shot reset to the active root span's close. Tracking
     * $rootCloseBoundRootId guarantees that many short-lived clients/providers
     * under one root do not each append a closure (the per-instance onClose
     * retention the review flagged).
     *
     * @param int $rootId
     */
    private function scheduleResetOnRootClose($rootId)
    {
        if ($this->rootCloseBoundRootId === $rootId) {
            return;
        }
        $this->rootCloseBoundRootId = $rootId;

        $reset = function () use ($rootId) {
            if ($this->rootId === $rootId && $this->accumulator !== null) {
                $this->accumulator->clear();
            }
            if ($this->rootId === $rootId) {
                $this->rootId = null;
            }
            if ($this->rootCloseBoundRootId === $rootId) {
                $this->rootCloseBoundRootId = null;
            }
        };

        if ($this->rootCloseScheduler !== null) {
            \call_user_func($this->rootCloseScheduler, $rootId, $reset);
            return;
        }

        if (!\function_exists('DDTrace\\root_span')) {
            return;
        }

        $root = \DDTrace\root_span();
        if ($root === null) {
            return;
        }

        $root->onClose = self::prependOnCloseReset($root->onClose, $reset);
    }

    /**
     * Prepend, not append, the reset closure to $onClose: the native close
     * path (tracer/span.c) runs $onClose in REVERSE registration order (last
     * registered runs first), so prepending guarantees this reset is the LAST
     * callback to run for this root regardless of how many other callbacks get
     * registered after it. If it instead ran before an earlier-registered
     * callback that itself evaluates a flag during root close, this reset would
     * look like a root-boundary crossing to that evaluation and wipe out the
     * union already staged for this root (PR review: reset ordering vs. other
     * onClose callbacks). Kept as its own method so the ordering guarantee is
     * directly testable without a real root span / native extension.
     *
     * @param mixed $onClose
     * @param callable $reset
     * @return array<int, callable>
     */
    private static function prependOnCloseReset($onClose, $reset)
    {
        if (!\is_array($onClose)) {
            $onClose = array();
        }
        \array_unshift($onClose, static function () use ($reset) {
            $reset();
        });

        return $onClose;
    }

    /**
     * Test accessor for the shared accumulator's currently-staged tag set
     * (the union of all evaluations seen on the active root). Returns an empty
     * array when nothing has been accumulated.
     *
     * @return array<string, string>
     */
    public function stagedTags()
    {
        if ($this->accumulator === null) {
            return array();
        }

        return $this->accumulator->toSpanTags();
    }
}
