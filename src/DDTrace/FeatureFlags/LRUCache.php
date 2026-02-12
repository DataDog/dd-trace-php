<?php

namespace DDTrace\FeatureFlags;

/**
 * Simple LRU (Least Recently Used) cache for exposure event deduplication.
 *
 * Uses an ordered associative array where the most recently accessed entries
 * are moved to the end. When the cache exceeds capacity, entries are evicted
 * from the front (least recently used).
 */
class LRUCache
{
    /** @var int */
    private $maxSize;

    /** @var array<string, mixed> */
    private $cache = [];

    /**
     * @param int $maxSize Maximum number of entries in the cache
     */
    public function __construct($maxSize = 65536)
    {
        $this->maxSize = $maxSize;
    }

    /**
     * Get a value from the cache by key.
     *
     * Accessing an entry promotes it to the most recently used position.
     *
     * @param string $key
     * @return mixed|null The cached value, or null if not found
     */
    public function get($key)
    {
        if (!array_key_exists($key, $this->cache)) {
            return null;
        }

        // Move to end (most recently used) by removing and re-adding
        $value = $this->cache[$key];
        unset($this->cache[$key]);
        $this->cache[$key] = $value;

        return $value;
    }

    /**
     * Set a value in the cache.
     *
     * If the key already exists, the value is updated and the entry is promoted
     * to the most recently used position. If the cache is at capacity, the least
     * recently used entry is evicted.
     *
     * @param string $key
     * @param mixed $value
     */
    public function set($key, $value)
    {
        // If key already exists, remove it first so it moves to the end
        if (array_key_exists($key, $this->cache)) {
            unset($this->cache[$key]);
        }

        $this->cache[$key] = $value;

        // Evict least recently used entry if over capacity
        if (count($this->cache) > $this->maxSize) {
            reset($this->cache);
            $evictKey = key($this->cache);
            unset($this->cache[$evictKey]);
        }
    }

    /**
     * Put a value in the cache and return the previous value.
     *
     * Like set(), but returns the old value (or null if the key was not present).
     * Always updates the LRU position, even when the value is unchanged.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed|null The previous value, or null if the key was new
     */
    public function put($key, $value)
    {
        $oldValue = null;
        if (array_key_exists($key, $this->cache)) {
            $oldValue = $this->cache[$key];
            unset($this->cache[$key]);
        }

        $this->cache[$key] = $value;

        // Evict least recently used entry if over capacity
        if (count($this->cache) > $this->maxSize) {
            reset($this->cache);
            $evictKey = key($this->cache);
            unset($this->cache[$evictKey]);
        }

        return $oldValue;
    }

    /**
     * Return the number of entries in the cache.
     *
     * @return int
     */
    public function size()
    {
        return count($this->cache);
    }

    /**
     * Clear all entries from the cache.
     */
    public function clear()
    {
        $this->cache = [];
    }
}
