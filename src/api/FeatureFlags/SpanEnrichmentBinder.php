<?php

namespace DDTrace\FeatureFlags;

/**
 * Binds APM feature-flag span enrichment to an evaluation code path.
 *
 * The per-root-span accumulation + encoding + root-boundary lifecycle was first
 * implemented inline in DDTrace\OpenFeature\DataDogProvider (DG-004: PHP's
 * OpenFeature SDK has no finally hook carrying ResolutionDetails, so enrichment
 * is accumulated INLINE on the evaluation path). That same machinery is needed
 * by the native DDTrace\FeatureFlags\Client, which evaluates flags WITHOUT going
 * through the OpenFeature provider (e.g. the parametric system-tests app and any
 * non-OpenFeature consumer). Extracting it here lets both paths stage identical
 * ffe_* tags from the same EvaluationDetails, keeping them in lockstep with the
 * native root-span close-write while honouring the FROZEN contract (limits,
 * delta-varint encoding, SHA256 subjects, runtime-default detection).
 *
 * DG-005: the binder allocates the accumulator lazily and only when the
 * experimental span-enrichment gate is on, so with the gate off there is no idle
 * per-evaluation or per-span overhead.
 *
 * PHP 7 compatible (lives in src/api alongside Client/SpanEnrichmentAccumulator).
 */
final class SpanEnrichmentBinder
{
    const SERIAL_ID_METADATA_KEY = 'serialId';
    const DO_LOG_METADATA_KEY = 'doLog';
    const SPAN_ENRICHMENT_CONFIG_KEY = 'DD_EXPERIMENTAL_FLAGGING_PROVIDER_SPAN_ENRICHMENT_ENABLED';

    /** @var bool */
    private $enabled = false;
    /** @var SpanEnrichmentAccumulator|null */
    private $accumulator = null;
    /** @var int|null Identity (spl_object_id) of the root span currently bound. */
    private $rootId = null;
    /** @var callable|null Test seam: resolve the current root-span id. */
    private $rootIdResolver = null;
    /** @var callable|null Test seam: schedule a one-shot reset on root close. */
    private $rootCloseScheduler = null;

    public function __construct($rootIdResolver = null, $rootCloseScheduler = null)
    {
        $this->rootIdResolver = $rootIdResolver;
        $this->rootCloseScheduler = $rootCloseScheduler;

        $this->enabled = self::gateEnabled();
        if ($this->enabled) {
            $this->accumulator = new SpanEnrichmentAccumulator();
        }
    }

    private static function gateEnabled()
    {
        return \function_exists('dd_trace_env_config')
            && \dd_trace_env_config(self::SPAN_ENRICHMENT_CONFIG_KEY) === true;
    }

    /**
     * Accumulate one evaluation's enrichment data and stage the encoded tag set
     * for the native close-span write. No-op when the gate is off. Mirrors the
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
        if (!$this->enabled || $this->accumulator === null) {
            return;
        }

        try {
            $this->resetForRootBoundary();

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
     * Reset the accumulator on a root-span boundary (CR-01) so it carries only
     * the active root span's evaluations and never leaks across spans/requests.
     */
    private function resetForRootBoundary()
    {
        if ($this->accumulator === null) {
            return;
        }

        $rootId = $this->currentRootSpanId();
        if ($rootId === $this->rootId) {
            return;
        }

        $this->accumulator->clear();
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

    private function currentRootSpanId()
    {
        if ($this->rootIdResolver !== null) {
            $id = \call_user_func($this->rootIdResolver);
            return $id === null ? null : (int) $id;
        }

        if (!\function_exists('DDTrace\\root_span')) {
            return null;
        }

        $root = \DDTrace\root_span();
        return $root !== null ? \spl_object_id($root) : null;
    }

    private function scheduleResetOnRootClose($rootId)
    {
        $self = $this;
        $reset = function () use ($self, $rootId) {
            if ($self->rootId === $rootId && $self->accumulator !== null) {
                $self->accumulator->clear();
            }
            if ($self->rootId === $rootId) {
                $self->rootId = null;
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

        $root->onClose[] = static function () use ($reset) {
            $reset();
        };
    }
}
