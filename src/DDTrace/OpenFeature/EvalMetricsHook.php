<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetricWriter;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\hooks\Hook;
use OpenFeature\interfaces\hooks\HookContext;
use OpenFeature\interfaces\hooks\HookHints;
use OpenFeature\interfaces\provider\ResolutionDetails;
use OpenFeature\interfaces\provider\ThrowableWithResolutionError;
use Throwable;

/**
 * OpenFeature `Hook` that records `feature_flag.evaluations` for PHP 8 OpenFeature
 * users. Matches the architectural pattern used by dd-trace-go, dd-trace-js,
 * dd-trace-java, and dd-trace-dotnet, where metrics are emitted via the
 * OpenFeature SDK's hook lifecycle.
 *
 * PHP 7 callers using `DDTrace\FeatureFlags\Client` directly continue to
 * record metrics via the internal `EvaluationCompletedHook` composite.
 * To avoid double-counting on the PHP 8 path, `DataDogProvider` constructs
 * its `Client` with `DefaultEvaluationCompletedHook::createWithoutMetric()`
 * (exposure-only), so the metric is recorded here instead.
 *
 * Allocation key is not carried in OpenFeature `ResolutionDetails` in the
 * PHP SDK (no `flagMetadata`). The provider stashes the most recent
 * `EvaluationDetails` and exposes it via a supplier callable so the hook
 * can read the allocation key when present. Single-threaded request flow
 * makes the stash safe.
 */
final class EvalMetricsHook implements Hook
{
    /** @var ?EvaluationMetricWriter */
    private $writer;

    /** @var callable():?EvaluationDetails */
    private $detailsSource;

    /**
     * @param ?EvaluationMetricWriter $writer Writer used to record metrics. `null` disables recording.
     * @param callable():?EvaluationDetails $detailsSource Supplier returning the last DD-side evaluation
     *        result for the current call, or null if no DD-side evaluation occurred (e.g. a `before`
     *        hook threw before the provider was invoked).
     */
    public function __construct($writer, callable $detailsSource)
    {
        if ($writer !== null && !$writer instanceof EvaluationMetricWriter) {
            throw new \InvalidArgumentException('Expected an EvaluationMetricWriter instance or null');
        }
        $this->writer = $writer;
        $this->detailsSource = $detailsSource;
    }

    public function before(HookContext $context, HookHints $hints): ?EvaluationContext
    {
        return null;
    }

    public function after(HookContext $context, ResolutionDetails $details, HookHints $hints): void
    {
        $reason = $details->getReason();
        $variant = $details->getVariant();
        $error = $details->getError();
        $errorCode = $error !== null ? (string) $error->getResolutionErrorCode() : null;

        $this->record($context->getFlagKey(), $variant, $reason, $errorCode);
    }

    public function error(HookContext $context, Throwable $error, HookHints $hints): void
    {
        $errorCode = $error instanceof ThrowableWithResolutionError
            ? (string) $error->getResolutionError()->getResolutionErrorCode()
            : 'GENERAL';

        $this->record($context->getFlagKey(), null, 'ERROR', $errorCode);
    }

    public function finally(HookContext $context, HookHints $hints): void
    {
        // Metric recording happens in after/error so that the resolution outcome
        // is available. No work needed here.
    }

    public function supportsFlagValueType(string $flagValueType): bool
    {
        return true;
    }

    private function record(string $flagKey, $variant, $reason, $errorCode): void
    {
        if ($this->writer === null) {
            return;
        }

        $allocationKey = null;
        $supplier = $this->detailsSource;
        $details = $supplier();
        if ($details instanceof EvaluationDetails) {
            $exposure = $details->getExposureData();
            if (is_array($exposure) && isset($exposure['allocationKey']) && is_string($exposure['allocationKey']) && $exposure['allocationKey'] !== '') {
                $allocationKey = $exposure['allocationKey'];
            }
        }

        try {
            $this->writer->recordAttributes($flagKey, $variant, $reason, $errorCode, $allocationKey);
        } catch (Throwable $throwable) {
            // Metric recording must never affect flag evaluation.
        }
    }
}
