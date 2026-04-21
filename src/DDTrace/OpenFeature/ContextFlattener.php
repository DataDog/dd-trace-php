<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

/**
 * Flattens nested PHP arrays into dot-notation keys with primitive-only values.
 *
 * Used to build the `subject.attributes` portion of exposure events (EXPO-05).
 * Nested structures like ['user' => ['plan' => 'enterprise']] become
 * ['user.plan' => 'enterprise'].
 *
 * Filtering rules:
 *   - Only string keys are accepted (numeric keys are dropped)
 *   - Only primitive values are kept: string, int, float, bool
 *   - Arrays are recursed into with dot-separated key prefix
 *   - null, objects, resources, and other types are silently dropped
 *
 * This mirrors the attribute extraction logic in Ruby's Event.extract_attributes()
 * and Python's _flatten_context() for cross-tracer consistency.
 *
 * @internal Used by ExposureWriter. Not part of the public API.
 */
final class ContextFlattener
{
    /**
     * Flatten nested arrays to dot-notation keys with primitive-only values.
     *
     * @param array<mixed> $data The nested array to flatten.
     * @param string $prefix Key prefix for recursion (empty string at top level).
     * @return array<string, string|int|float|bool> Flattened key-value pairs.
     */
    public static function flatten(array $data, string $prefix = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            // Only string keys are accepted (numeric array indices are dropped)
            if (!is_string($key)) {
                continue;
            }

            $fullKey = $prefix === '' ? $key : $prefix . '.' . $key;

            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $result[$fullKey] = $value;
            } elseif (is_array($value)) {
                $result = array_merge($result, self::flatten($value, $fullKey));
            }
            // null, objects, resources are silently dropped
        }

        return $result;
    }
}
