<?php

namespace DDTrace\Util;


/**
 *
 */
class ObjectKVStore
{
    private static $KEY_PREFIX = '__dd_store_';

    /**
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
     * @param mixed $instance_source
     * @param mixed $instance_destination
     * @param string $key
     */
    public static function propagate($instance_source, $instance_destination, $key)
    {
        self::put($instance_destination, $key, self::get($instance_source, $key));
    }

    /**
     * @param string $key
     * @return string
     */
    private static function getScopedKeyName($key)
    {
        return self::$KEY_PREFIX . $key;
    }

    /**
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
