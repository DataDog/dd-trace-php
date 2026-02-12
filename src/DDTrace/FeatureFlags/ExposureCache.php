<?php

namespace DDTrace\FeatureFlags;

/**
 * LRU-based exposure event deduplication cache.
 *
 * Mirrors the canonical Java implementation (LRUExposureCache).
 * Key = (flagKey, subjectId), Value = (variantKey, allocationKey).
 * Returns true from add() when the event is new or its value changed,
 * false when it's an exact duplicate. Even duplicates update the LRU
 * position to keep hot entries from being evicted.
 */
class ExposureCache
{
    /** @var LRUCache */
    private $cache;

    /**
     * @param int $capacity Maximum number of entries
     */
    public function __construct($capacity = 65536)
    {
        $this->cache = new LRUCache($capacity);
    }

    /**
     * Add an exposure event to the cache.
     *
     * @param string $flagKey
     * @param string $subjectId
     * @param string $variantKey
     * @param string $allocationKey
     * @return bool true if the event is new or value changed, false if exact duplicate
     */
    public function add($flagKey, $subjectId, $variantKey, $allocationKey)
    {
        $key = self::makeKey($flagKey, $subjectId);
        $newValue = self::makeValue($variantKey, $allocationKey);

        // Always put (updates LRU position even for duplicates)
        $oldValue = $this->cache->put($key, $newValue);

        return $oldValue === null || $oldValue !== $newValue;
    }

    /**
     * Get the cached value for a (flag, subject) pair.
     *
     * @param string $flagKey
     * @param string $subjectId
     * @return array|null [variantKey, allocationKey] or null if not found
     */
    public function get($flagKey, $subjectId)
    {
        $key = self::makeKey($flagKey, $subjectId);
        $value = $this->cache->get($key);
        if ($value === null) {
            return null;
        }
        return self::parseValue($value);
    }

    /**
     * Return the number of entries in the cache.
     *
     * @return int
     */
    public function size()
    {
        return $this->cache->size();
    }

    /**
     * Clear all entries.
     */
    public function clear()
    {
        $this->cache->clear();
    }

    /**
     * Build a composite key that avoids collision.
     * Uses length-prefixing: "<len>:<flag>:<subject>"
     */
    private static function makeKey($flagKey, $subjectId)
    {
        $f = $flagKey !== null ? $flagKey : '';
        $s = $subjectId !== null ? $subjectId : '';
        return strlen($f) . ':' . $f . ':' . $s;
    }

    /**
     * Build a composite value string.
     */
    private static function makeValue($variantKey, $allocationKey)
    {
        $v = $variantKey !== null ? $variantKey : '';
        $a = $allocationKey !== null ? $allocationKey : '';
        return strlen($v) . ':' . $v . ':' . $a;
    }

    /**
     * Parse a composite value string back into [variantKey, allocationKey].
     */
    private static function parseValue($value)
    {
        $colonPos = strpos($value, ':');
        if ($colonPos === false) {
            return [$value, ''];
        }
        $len = (int) substr($value, 0, $colonPos);
        $variant = substr($value, $colonPos + 1, $len);
        $allocation = substr($value, $colonPos + 1 + $len + 1);
        return [$variant, $allocation];
    }
}
