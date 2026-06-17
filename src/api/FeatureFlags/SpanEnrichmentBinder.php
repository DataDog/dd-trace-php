<?php

namespace DDTrace\FeatureFlags;

/**
 * Thin, gate-gated adapter that binds an evaluation code path to the shared
 * request-scoped span-enrichment accumulator (SpanEnrichmentRegistry).
 *
 * History: the per-root-span accumulation + encoding + root-boundary lifecycle
 * was first implemented inline in DDTrace\OpenFeature\DataDogProvider (DG-004:
 * PHP's OpenFeature SDK has no finally hook carrying ResolutionDetails, so
 * enrichment is accumulated INLINE on the evaluation path). The same machinery
 * is needed by the native DDTrace\FeatureFlags\Client, which evaluates WITHOUT
 * the OpenFeature provider.
 *
 * Originally each binder owned its OWN accumulator and staged independently.
 * That overwrote rather than aggregated when two clients/providers (or a mixed
 * OpenFeature + native evaluation) ran under one root span, because the native
 * staging API replaces the request-global tag slots wholesale (PR review
 * blocker). It also retained a per-binder onClose closure per root span (PR
 * review should-fix). Both are fixed by delegating to the single shared
 * SpanEnrichmentRegistry: one accumulator aggregates all paths, and the
 * registry binds at most one root-close reset per root span.
 *
 * DG-005: this binder is constructed ONLY when the experimental span-enrichment
 * gate is on (see DDTrace\FeatureFlags\Client). With the gate off no binder is
 * allocated and accumulate() is never reached, so there is no idle per-span
 * overhead.
 *
 * PHP 7 compatible (lives in src/api alongside Client / SpanEnrichmentRegistry).
 */
final class SpanEnrichmentBinder
{
    const SPAN_ENRICHMENT_CONFIG_KEY = 'DD_EXPERIMENTAL_FLAGGING_PROVIDER_SPAN_ENRICHMENT_ENABLED';

    /**
     * Whether the experimental span-enrichment gate is on. Read once: the gate
     * is process-level (an env-backed config) and cannot toggle mid-request.
     *
     * @return bool
     */
    public static function gateEnabled()
    {
        return \function_exists('dd_trace_env_config')
            && \dd_trace_env_config(self::SPAN_ENRICHMENT_CONFIG_KEY) === true;
    }

    /**
     * Accumulate one evaluation's enrichment via the shared registry. Only
     * called when the gate is on (the caller constructs no binder otherwise).
     *
     * @param string $flagKey
     * @param EvaluationDetails $details
     * @param string|null $targetingKey
     */
    public function accumulate($flagKey, $details, $targetingKey)
    {
        SpanEnrichmentRegistry::instance()->accumulate($flagKey, $details, $targetingKey);
    }
}
