<?php

namespace DDTrace\FeatureFlags;

use DDTrace\Util\ObjectKVStore;

/**
 * APM feature-flag span enrichment: attach evaluated-flag metadata to the root
 * span so APM can filter traces/errors by active flag variant.
 *
 * State lives ON the root span (weak-keyed via ObjectKVStore), matching the
 * cross-SDK design principle "state on the span/trace, not a central store"
 * and every other SDK (JS WeakMap / Python WeakKeyDictionary / Ruby WeakMap /
 * Java WeakHashMap keyed by root span / dotnet field on TraceContext). Because
 * the accumulator is bound to the span object itself, its identity and lifetime
 * ARE the span's: multiple evaluations under one root aggregate into the same
 * accumulator, concurrently-open roots each keep their own, and everything is
 * released with the span -- no manual root-id tracking or reset lifecycle.
 *
 * The encoded ffe_* tags are written directly onto the root span's meta (the
 * same mechanism the rest of the tracer uses to set tags); there is no native
 * staging layer.
 *
 * Gated by DD_EXPERIMENTAL_FLAGGING_PROVIDER_SPAN_ENRICHMENT_ENABLED (off by
 * default): when the gate is off, record() is a cheap no-op.
 *
 * Only ever reached from NativeEvaluator::evaluate(), i.e. with the tracer
 * extension loaded (UnavailableEvaluator is used otherwise), so \DDTrace\root_span()
 * and dd_trace_env_config() are always available here.
 *
 * PHP 7 compatible (lives in src/api alongside Client / SpanEnrichmentAccumulator,
 * consumed by the PHP 8-only DataDogProvider via the shared NativeEvaluator).
 */
final class SpanEnrichmentRegistry
{
    const ACCUMULATOR_KEY = 'ffe_accumulator';
    const SERIAL_ID_METADATA_KEY = 'serialId';
    const DO_LOG_METADATA_KEY = 'doLog';
    const CONFIG_KEY = 'DD_EXPERIMENTAL_FLAGGING_PROVIDER_SPAN_ENRICHMENT_ENABLED';

    /**
     * Whether the experimental span-enrichment gate is on. Process-level env
     * config; cannot toggle mid-request.
     *
     * @return bool
     */
    public static function gateEnabled()
    {
        return \dd_trace_env_config(self::CONFIG_KEY) === true;
    }

    /**
     * Record one evaluation's enrichment onto the current root span, then write
     * the encoded ffe_* tags onto the root's meta. No-op when the gate is off or
     * there is no active root span. Never throws into flag evaluation.
     *
     * Mirrors the frozen Node reference branch: a present serial id is recorded
     * (and, when do_log authorizes and a targeting key exists, a hashed subject);
     * an evaluation with no serial id and no variant is a runtime default.
     *
     * @param string $flagKey
     * @param EvaluationDetails $details
     * @param string|null $targetingKey
     */
    public static function record($flagKey, $details, $targetingKey)
    {
        try {
            if (!self::gateEnabled()) {
                return;
            }

            $root = \DDTrace\root_span();
            if ($root === null) {
                return;
            }

            $accumulator = ObjectKVStore::get($root, self::ACCUMULATOR_KEY);
            if (!$accumulator instanceof SpanEnrichmentAccumulator) {
                $accumulator = new SpanEnrichmentAccumulator();
                ObjectKVStore::put($root, self::ACCUMULATOR_KEY, $accumulator);
            }

            $exposure = $details->getExposureData();
            $serialId = is_array($exposure) && array_key_exists(self::SERIAL_ID_METADATA_KEY, $exposure)
                ? $exposure[self::SERIAL_ID_METADATA_KEY]
                : null;
            $doLog = is_array($exposure) && !empty($exposure[self::DO_LOG_METADATA_KEY]);

            if ($serialId !== null) {
                $accumulator->addSerialId((int) $serialId);
                if ($doLog && $targetingKey !== null && $targetingKey !== '') {
                    $accumulator->addSubject($targetingKey, (int) $serialId);
                }
            } else {
                $variant = $details->getVariant();
                if ($variant === null || $variant === '') {
                    $accumulator->addDefault((string) $flagKey, $details->getValue());
                }
            }

            // Write (overwrite) the encoded union onto the root span's meta. Safe
            // to do on every evaluation: toSpanTags() re-encodes the accumulator's
            // full state, so the latest write is always the complete union.
            foreach ($accumulator->toSpanTags() as $key => $value) {
                $root->meta[$key] = $value;
            }
        } catch (\Throwable $e) {
            // Enrichment must never break flag evaluation.
        }
    }
}
