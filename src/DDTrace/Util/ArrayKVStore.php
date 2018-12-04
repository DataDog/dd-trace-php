<?php

namespace DDTrace\Util;

/**
 * A key value store that stores metadata into an array. If you have an object that you can use as a carrier, then
 * prefer ObjectKVStore as is provides a better performance. Use this if you do not have an object you can use as
 * a carrier.
 */
class ArrayKVStore
{
    private static $resource_registry = [];

    /**
     * Put or replaces a key with a specific value.
     *
     * @param resource $resource
     * @param string $key
     * @param mixed $value
     */
    public static function putForResource($resource, $key, $value)
    {
        if (self::notEnoughResourceInfo($resource, $key)) {
            return;
        }
        self::$resource_registry[self::getResourceKey($resource)][$key] = $value;
    }

    /**
     * Extract a key's value from an instance. If the key is not set => fallbacks to default.
     *
     * @param resource $resource
     * @param string $key
     * @param mixed $default
     * @return mixed|null
     */
    public static function getForResource($resource, $key, $default = null)
    {
        if (self::notEnoughResourceInfo($resource, $key)) {
            return $default;
        }
        $resourceKey = self::getResourceKey($resource);
        return isset(self::$resource_registry[$resourceKey][$key])
            ? self::$resource_registry[$resourceKey][$key]
            : $default;
    }

    /**
     * Delete a key's value from an instance, if present.
     *
     * @param resource $resource
     */
    public static function deleteResource($resource)
    {
        $resourceKey = self::getResourceKey($resource);
        unset(self::$resource_registry[$resourceKey]);
    }

    /**
     * Clears the storage.
     */
    public static function clear()
    {
        self::$resource_registry = [];
    }

    /**
     * Tells whether or not a set of info is enough to be used in this storage.
     *
     * @param resource $resource
     * @param string $key
     * @return bool
     */
    private static function notEnoughResourceInfo($resource, $key)
    {
        return
            !is_resource($resource)
            || empty($key)
            || !is_string($key);
    }

    /**
     * Returns the unique resource key.
     *
     * @param resource $resource
     * @return int
     */
    private static function getResourceKey($resource)
    {
        // Converting to integer a resource results in the "unique resource number assigned to the resource by PHP at
        // runtime":
        //   - http://php.net/manual/en/language.types.integer.php#language.types.integer.casting
        // Resource ids are guaranteed to be unique per script execution:
        //   - http://www.php.net/manual/en/language.types.string.php#language.types.string.casting
        return intval($resource);
    }
}
