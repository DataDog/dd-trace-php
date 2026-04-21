<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use DateTime;
use OpenFeature\interfaces\flags\EvaluationContext;

/**
 * Normalizes OpenFeature EvaluationContext into the shape expected by DDTrace\ffe_evaluate().
 *
 * The bridge accepts:
 *   - A nullable targeting key (string|null)
 *   - A flat array of primitive-only attributes (string keys, values: string|int|float|bool)
 *
 * This normalizer:
 *   - Preserves null targeting key when absent (does NOT default to empty string)
 *   - Extracts targeting key when present
 *   - Filters attributes to flat primitive values only (string, int, float, bool)
 *   - Drops nested arrays, objects, DateTime, null, and any other unsupported values
 *
 * Mirrors the primitive-only filtering done in ext/ddtrace.c attribute marshaling.
 *
 * @internal Used by DataDogProvider. Not part of the public API.
 */
final class EvaluationContextNormalizer
{
    /**
     * Normalize an EvaluationContext for bridge consumption.
     *
     * @param EvaluationContext|null $context The OpenFeature evaluation context
     * @return array{0: ?string, 1: array<string, bool|string|int|float>}
     *         Tuple of [targetingKey, flatPrimitiveAttributes]
     */
    public function normalize(?EvaluationContext $context): array
    {
        if ($context === null) {
            return [null, []];
        }

        $targetingKey = $context->getTargetingKey();
        $rawAttributes = $context->getAttributes()->toArray();
        $filtered = $this->filterPrimitiveAttributes($rawAttributes);

        return [$targetingKey, $filtered];
    }

    /**
     * Filter an attributes array to only flat primitive values.
     *
     * Accepted types: string, int, float, bool
     * Dropped types: array, object, DateTime, null, resource, or any non-primitive
     *
     * Only string keys are forwarded (matching ext/ddtrace.c behavior which
     * skips integer-keyed entries in ZEND_HASH_FOREACH_STR_KEY_VAL).
     *
     * @param array<array-key, mixed> $attributes Raw attributes from EvaluationContext
     * @return array<string, bool|string|int|float> Filtered primitive-only attributes
     */
    private function filterPrimitiveAttributes(array $attributes): array
    {
        $filtered = [];

        foreach ($attributes as $key => $value) {
            // Only string keys are accepted (mirrors C extension behavior)
            if (!is_string($key)) {
                continue;
            }

            // Only flat primitive types are forwarded
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $filtered[$key] = $value;
            }
            // All other types (array, object, DateTime, null, resource) are silently dropped
        }

        return $filtered;
    }
}
