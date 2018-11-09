<?php

namespace DDTrace\Util;


/**
 * A key value store that stores metadata into object instances.
 *
 * Performance considerations. We basically have a couple of simple approached to get values stored in memory per
 * objects:
 *   - save data into an in-line property, e.g. `__dd_store_<some_key>`, pf an obj instance
 *   - use an array and a way of identifying an object to scope a key to a specific object.
 *
 * While more in-depth tests can be performed, we can provide a very quick implementation of a simple script that
 * shows how using the inline approach can be a first attempt that is much more performing than using a registry. E.g.
 *
 *     $repetitions = 1000000;
 *     $propertyName = '__dd_store_key';
 *
 *     $objects_in_place = [];
 *     $objects_array = [];
 *
 *     // Preparing objects
 *     for ($i = 0; $i < $repetitions; $i++) {
 *         $objects_in_place[] = new stdClass();
 *         $objects_array[] = new stdClass();
 *     }
 *
 *     function measure($what, $callable) {
 *         echo $what . "\n";
 *         $start = microtime(true);
 *         $callable();
 *         $end = microtime(true);
 *         echo "  -> took secs: " . ($end - $start);
 *         echo "\n\n";
 *     }
 *
 *     $registry_flat = [];
 *     measure('Put object in flat array', function () use ($repetitions, $objects_array, $registry_flat) {
 *         for ($i = 0; $i < $repetitions; $i++) {
 *             $key = spl_object_hash($objects_array[$i]) . '_key';
 *             $registry_flat[$key] = 'Sam';
 *         }
 *     });
 *
 *     measure('Get object in flat array', function () use ($repetitions, $objects_array, $registry_flat) {
 *         for ($i = 0; $i < $repetitions; $i++) {
 *             $key = spl_object_hash($objects_array[$i]) . '_key';
 *             $a = $registry_flat[$key];
 *         }
 *     });
 *
 *     $registry_composed = [];
 *     measure('Put object in structured array', function () use ($repetitions, $objects_array, $registry_composed) {
 *         for ($i = 0; $i < $repetitions; $i++) {
 *             $hash = spl_object_hash($objects_array[$i]);
 *             if (!array_key_exists($hash, $registry_composed)) {
 *                 $registry_composed[$hash] = [];
 *             }
 *             $registry_composed['key'] = 'Sam';
 *         }
 *     });
 *
 *     measure('Get object in structured array', function () use ($repetitions, $objects_array, $registry_composed) {
 *         for ($i = 0; $i < $repetitions; $i++) {
 *             $hash = spl_object_hash($objects_array[$i]);
 *             if (!empty($registry_composed[$hash]['key'])) {
 *                 $a = $registry_composed[$hash]['key'];
 *             }
 *         }
 *     });
 *
 *     measure('Put object in place', function () use ($repetitions, $objects_in_place, $propertyName) {
 *         for ($i = 0; $i < $repetitions; $i++) {
 *             $objects_in_place[$i]->$propertyName = 'Sam';
 *         }
 *     });
 *
 *     measure('Get object in place', function () use ($repetitions, $objects_in_place, $propertyName) {
 *         for ($i = 0; $i < $repetitions; $i++) {
 *             $a = $objects_in_place[$i]->$propertyName;
 *         }
 *     });
 *
 *
 * The above script outputs the following result:
 *
 *      $ php -d memory_limit=-1 playground.php
 *
 *      Put object in flat array
 *        -> took secs: 3.916533946991
 *
 *      Get object in flat array
 *        -> took secs: 3.8086051940918
 *
 *      Put object in structured array
 *        -> took secs: 10.850040912628
 *
 *      Get object in structured array
 *        -> took secs: 3.7683751583099
 *
 *      Put object in place
 *        -> took secs: 0.62740993499756
 *
 *      Get object in place
 *        -> took secs: 0.12619400024414
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
