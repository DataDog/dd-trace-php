<?php

namespace DDTrace\Util;


/**
 * A key value store that stores metada into object instances.
 */
class ObjectKVStore
{
    private static $KEY_PREFIX = '__dd_store_';

    /**
     * Put or replaces a key with a specific value.
     *
     * @param mixed $instance
     * @param string $key
     * @param mixed $value
     */
    public static function put($instance, $key, $value)
    {
        if (self::isIncompleteInfo($instance, $key)) {
            return;
        }
        $scopedKey = self::getScopedKeyName($key);
        $instance->$scopedKey = $value;
    }

    /**
     * Extract a key's value from an instance. If the key is not set => fallbacks to default.
     *
     * @param mixed $instance
     * @param string $key
     * @param mixed $default
     * @return mixed|null
     */
    public static function get($instance, $key, $default = null)
    {
        if (self::isIncompleteInfo($instance, $key)) {
            return $default;
        }
        $scopedKey = self::getScopedKeyName($key);
        return property_exists($instance, $scopedKey) ? $instance->$scopedKey : $default;
    }

    /**
     * Copy a value from a source instance to a destination instance.
     *
     * @param mixed $instance_source
     * @param mixed $instance_destination
     * @param string $key
     */
    public static function propagate($instance_source, $instance_destination, $key)
    {
        self::put($instance_destination, $key, self::get($instance_source, $key));
    }

    /**
     * Given a human-friendly key name, return a modified version of the key which is scoped into a Datadog namespace.
     *
     * @param string $key
     * @return string
     */
    private static function getScopedKeyName($key)
    {
        return self::$KEY_PREFIX . $key;
    }

    /**
     * Tells whether or not a set of info is enough to be used as a store.
     *
     * @param mixed $instance
     * @param string $key
     * @return bool
     */
    private static function isIncompleteInfo($instance, $key)
    {
        return
            empty($instance)
            || !is_object($instance)
            || empty($key)
            || !is_string($key);
    }
}
