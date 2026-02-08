<?php

namespace DDTrace\FeatureFlags;

/**
 * UFC (Universal Feature Configuration) v1 evaluation engine.
 *
 * Receives flag configurations in UFC format and evaluates flags
 * based on targeting rules, allocations, and sharding.
 *
 * @internal This class may be changed at any time (private API) and shall not be relied on in consumer code.
 */
class Evaluator
{
    /** @var array|null */
    private $config = null;

    /**
     * Set the UFC configuration.
     *
     * @param array $config The decoded UFC configuration
     * @return void
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    /**
     * Resolve a feature flag to its value.
     *
     * @param string $flagKey The flag key to resolve
     * @param string $expectedType The expected variation type (STRING, BOOLEAN, INTEGER, NUMERIC, JSON)
     * @param array  $context Evaluation context with 'targeting_key' and 'attributes'
     * @return array|null Resolution details array or null
     */
    public function resolveFlag(string $flagKey, string $expectedType, array $context)
    {
        if ($this->config === null) {
            return $this->makeError('FLAG_NOT_FOUND', 'No configuration loaded', $flagKey);
        }

        $flags = isset($this->config['flags']) ? $this->config['flags'] : [];

        if (!isset($flags[$flagKey])) {
            return $this->makeError('FLAG_NOT_FOUND', "Flag '{$flagKey}' not found", $flagKey);
        }

        $flag = $flags[$flagKey];

        if (!isset($flag['enabled']) || $flag['enabled'] !== true) {
            return [
                'value' => null,
                'variant' => null,
                'reason' => 'DISABLED',
                'error_code' => null,
                'error_message' => null,
                'allocation_key' => null,
                'do_log' => false,
            ];
        }

        $variationType = isset($flag['variationType']) ? $flag['variationType'] : null;
        if ($variationType !== null && $expectedType !== $variationType) {
            return $this->makeError(
                'TYPE_MISMATCH',
                "Expected type '{$expectedType}' but flag has type '{$variationType}'",
                $flagKey
            );
        }

        $result = $this->evaluateAllocations($flag, $context);

        if ($result === null) {
            return [
                'value' => null,
                'variant' => null,
                'reason' => 'DEFAULT',
                'error_code' => null,
                'error_message' => null,
                'allocation_key' => null,
                'do_log' => false,
            ];
        }

        return $result;
    }

    /**
     * Evaluate allocations for a flag.
     *
     * @param array $flag The flag configuration
     * @param array $context Evaluation context
     * @return array|null Resolution details or null if no allocation matches
     */
    private function evaluateAllocations(array $flag, array $context)
    {
        $allocations = isset($flag['allocations']) ? $flag['allocations'] : [];
        $variations = isset($flag['variations']) ? $flag['variations'] : [];

        foreach ($allocations as $allocation) {
            // Check time constraints
            if (isset($allocation['startAt'])) {
                $startAt = strtotime($allocation['startAt']);
                if ($startAt !== false && time() < $startAt) {
                    continue;
                }
            }

            if (isset($allocation['endAt'])) {
                $endAt = strtotime($allocation['endAt']);
                if ($endAt !== false && time() >= $endAt) {
                    continue;
                }
            }

            // Evaluate rules if present (rules are OR-ed)
            $rules = isset($allocation['rules']) ? $allocation['rules'] : [];
            if (!empty($rules)) {
                if (!$this->evaluateRules($rules, $context)) {
                    continue;
                }
            }

            // Evaluate splits
            $splits = isset($allocation['splits']) ? $allocation['splits'] : [];
            $targetingKey = isset($context['targeting_key']) ? $context['targeting_key'] : null;

            $matchedVariationKey = $this->evaluateSplits($splits, $targetingKey);

            if ($matchedVariationKey !== null) {
                if (!isset($variations[$matchedVariationKey])) {
                    continue;
                }

                $variation = $variations[$matchedVariationKey];
                $value = isset($variation['value']) ? $variation['value'] : null;
                $allocationKey = isset($allocation['key']) ? $allocation['key'] : null;
                $doLog = isset($allocation['doLog']) ? (bool)$allocation['doLog'] : true;

                $reason = !empty($rules) ? 'TARGETING_MATCH' : 'SPLIT';
                if (count($splits) === 1 && !isset($splits[0]['shards'])) {
                    $reason = !empty($rules) ? 'TARGETING_MATCH' : 'SPLIT';
                }

                return [
                    'value' => $value,
                    'variant' => $matchedVariationKey,
                    'reason' => $reason,
                    'error_code' => null,
                    'error_message' => null,
                    'allocation_key' => $allocationKey,
                    'do_log' => $doLog,
                ];
            }
        }

        return null;
    }

    /**
     * Evaluate targeting rules (OR logic: any rule matching is sufficient).
     *
     * @param array $rules Array of rules
     * @param array $context Evaluation context
     * @return bool True if at least one rule matches
     */
    private function evaluateRules(array $rules, array $context)
    {
        foreach ($rules as $rule) {
            $conditions = isset($rule['conditions']) ? $rule['conditions'] : [];
            $allConditionsMet = true;

            foreach ($conditions as $condition) {
                if (!$this->evaluateCondition($condition, $context)) {
                    $allConditionsMet = false;
                    break;
                }
            }

            if ($allConditionsMet) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate a single condition against the context.
     *
     * @param array $condition The condition to evaluate
     * @param array $context Evaluation context
     * @return bool True if the condition is satisfied
     */
    private function evaluateCondition(array $condition, array $context)
    {
        $operator = isset($condition['operator']) ? $condition['operator'] : '';
        $attribute = isset($condition['attribute']) ? $condition['attribute'] : '';
        $conditionValue = isset($condition['value']) ? $condition['value'] : null;

        // Resolve the attribute value from context
        $attributeValue = $this->getAttributeValue($attribute, $context);

        switch ($operator) {
            case 'IS_NULL':
                $expectNull = (bool)$conditionValue;
                if ($expectNull) {
                    return $attributeValue === null;
                } else {
                    return $attributeValue !== null;
                }

            case 'ONE_OF':
                if ($attributeValue === null) {
                    return false;
                }
                return $this->matchesOneOf($attributeValue, $conditionValue);

            case 'NOT_ONE_OF':
                if ($attributeValue === null) {
                    return false;
                }
                return !$this->matchesOneOf($attributeValue, $conditionValue);

            case 'MATCHES':
                if ($attributeValue === null) {
                    return false;
                }
                return $this->matchesRegex($attributeValue, $conditionValue);

            case 'NOT_MATCHES':
                if ($attributeValue === null) {
                    return false;
                }
                return !$this->matchesRegex($attributeValue, $conditionValue);

            case 'LT':
                if ($attributeValue === null) {
                    return false;
                }
                return $this->toNumeric($attributeValue) < $this->toNumeric($conditionValue);

            case 'LTE':
                if ($attributeValue === null) {
                    return false;
                }
                return $this->toNumeric($attributeValue) <= $this->toNumeric($conditionValue);

            case 'GT':
                if ($attributeValue === null) {
                    return false;
                }
                return $this->toNumeric($attributeValue) > $this->toNumeric($conditionValue);

            case 'GTE':
                if ($attributeValue === null) {
                    return false;
                }
                return $this->toNumeric($attributeValue) >= $this->toNumeric($conditionValue);

            default:
                return false;
        }
    }

    /**
     * Evaluate splits to find a matching variation key.
     *
     * @param array       $splits Array of split configurations
     * @param string|null $targetingKey The targeting key from context
     * @return string|null The matched variation key, or null if no split matches
     */
    private function evaluateSplits(array $splits, $targetingKey)
    {
        foreach ($splits as $split) {
            $shards = isset($split['shards']) ? $split['shards'] : [];

            // If no shards defined, this split always matches
            if (empty($shards)) {
                return isset($split['variationKey']) ? $split['variationKey'] : null;
            }

            // Need a targeting key for shard evaluation
            if ($targetingKey === null) {
                continue;
            }

            foreach ($shards as $shard) {
                $salt = isset($shard['salt']) ? $shard['salt'] : '';
                $totalShards = isset($shard['totalShards']) ? (int)$shard['totalShards'] : 0;
                $ranges = isset($shard['ranges']) ? $shard['ranges'] : [];

                if ($totalShards <= 0) {
                    continue;
                }

                $shardValue = $this->computeShard($salt, $targetingKey, $totalShards);

                $inRange = false;
                foreach ($ranges as $range) {
                    $start = isset($range['start']) ? (int)$range['start'] : 0;
                    $end = isset($range['end']) ? (int)$range['end'] : 0;

                    if ($shardValue >= $start && $shardValue < $end) {
                        $inRange = true;
                        break;
                    }
                }

                if (!$inRange) {
                    // This shard did not match; the split does not match
                    // All shards in a split must match
                    continue 2;
                }
            }

            // All shards matched for this split
            return isset($split['variationKey']) ? $split['variationKey'] : null;
        }

        return null;
    }

    /**
     * Compute the shard value for a given salt, targeting key, and total shards.
     *
     * Uses MD5 hash of "salt-targetingKey" and takes the first 4 bytes as a
     * big-endian unsigned 32-bit integer, then takes modulo totalShards.
     *
     * @param string $salt The shard salt
     * @param string $targetingKey The targeting key
     * @param int    $totalShards Total number of shards
     * @return int The computed shard value [0, totalShards)
     */
    private function computeShard(string $salt, string $targetingKey, int $totalShards)
    {
        $hashInput = $salt . '-' . $targetingKey;
        $hashHex = md5($hashInput);

        // Use the first 8 hex characters (4 bytes, 32 bits) interpreted as
        // a big-endian unsigned 32-bit integer, matching the Go reference implementation.
        $hashInt = hexdec(substr($hashHex, 0, 8));

        return $hashInt % $totalShards;
    }

    /**
     * Get an attribute value from the evaluation context.
     *
     * @param string $attribute The attribute name
     * @param array  $context The evaluation context
     * @return mixed The attribute value or null if not found
     */
    private function getAttributeValue(string $attribute, array $context)
    {
        $attributes = isset($context['attributes']) ? $context['attributes'] : [];

        // Check attributes dictionary first
        if (array_key_exists($attribute, $attributes)) {
            return $attributes[$attribute];
        }

        // Fall back to targeting key for 'id' and 'targetingKey' attributes
        if ($attribute === 'id' || $attribute === 'targetingKey') {
            return isset($context['targeting_key']) ? $context['targeting_key'] : null;
        }

        return null;
    }

    /**
     * Check if an attribute value matches one of the values in the list (ONE_OF).
     *
     * @param mixed $attributeValue The attribute value
     * @param array $conditionValues The list of acceptable values
     * @return bool True if attribute matches one of the values
     */
    private function matchesOneOf($attributeValue, $conditionValues)
    {
        if (!is_array($conditionValues)) {
            return false;
        }

        $stringValue = $this->toStringForComparison($attributeValue);

        foreach ($conditionValues as $cv) {
            $cvString = (string)$cv;
            if ($stringValue === $cvString) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an attribute value matches a regex pattern.
     *
     * @param mixed  $attributeValue The attribute value
     * @param string $pattern The regex pattern
     * @return bool True if the attribute value matches the pattern
     */
    private function matchesRegex($attributeValue, $pattern)
    {
        if (!is_string($pattern)) {
            return false;
        }

        // Convert boolean attribute values to string representation
        $stringValue = $this->toStringForComparison($attributeValue);

        // The pattern from UFC is a raw regex string; we need to wrap it in delimiters
        $delimiter = '/';
        // Escape any unescaped forward slashes in the pattern
        $escapedPattern = str_replace('/', '\\/', $pattern);
        $fullPattern = $delimiter . $escapedPattern . $delimiter;

        // Suppress warnings for invalid regex patterns
        $result = @preg_match($fullPattern, $stringValue);

        return $result === 1;
    }

    /**
     * Convert a value to a string for comparison in ONE_OF and MATCHES operators.
     *
     * Boolean true -> "true", boolean false -> "false".
     * All other values are cast to string normally.
     *
     * @param mixed $value The value to convert
     * @return string The string representation
     */
    private function toStringForComparison($value)
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string)$value;
    }

    /**
     * Convert a value to numeric for comparison operators.
     *
     * @param mixed $value The value to convert
     * @return float|int The numeric value
     */
    private function toNumeric($value)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return $value + 0; // PHP auto-converts to int or float
        }

        return 0;
    }

    /**
     * Build an error resolution result.
     *
     * @param string $errorCode The error code
     * @param string $errorMessage The error message
     * @param string $flagKey The flag key
     * @return array The error resolution details
     */
    private function makeError(string $errorCode, string $errorMessage, string $flagKey)
    {
        return [
            'value' => null,
            'variant' => null,
            'reason' => 'ERROR',
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'allocation_key' => null,
            'do_log' => false,
        ];
    }
}
