<?php

namespace DDTrace\FeatureFlags\Internal;

use DDTrace\Log\Logger;
use DDTrace\Log\NonThrowingLogger;

/**
 * Routes the selected split's features into flat flag metadata, one entry per
 * feature and destination.
 *
 * @internal
 */
final class SplitFeatures
{
    const HOOK_PREFIX = '__dd_feature.';
    const EXPOSURE_PREFIX = '__dd_exposure_feature.';
    const EVALUATION_PREFIX = '__dd_evaluation_feature.';
    const DO_LOG_KEY = '__dd_do_log';
    const SERIAL_ID_KEY = '__dd_split_serial_id';
    const EXPOSURE_CACHE_HIT_KEY = '__dd_exposure_cache_hit';

    const WARNED_DESTINATIONS_LIMIT = 64;

    private static $prefixes = array(
        'HOOK' => self::HOOK_PREFIX,
        'EXPOSURE' => self::EXPOSURE_PREFIX,
        'EVALUATION' => self::EVALUATION_PREFIX,
    );

    /** @var array<string, true> */
    private static $warnedDestinations = array();

    /**
     * @param mixed $features The native list of {key, value, destinations} entries.
     * @return array<string, string|float|bool>
     */
    public static function toMetadata($features)
    {
        $metadata = array();
        if (!is_array($features)) {
            return $metadata;
        }

        $seen = array();
        foreach ($features as $feature) {
            if (!is_array($feature)
                || !isset($feature['key'], $feature['destinations'])
                || !is_string($feature['key'])
                || $feature['key'] === ''
                || !array_key_exists('value', $feature)
                || !(is_string($feature['value']) || is_float($feature['value']) || is_int($feature['value']) || is_bool($feature['value']))
                || !is_array($feature['destinations'])
                || isset($seen[$feature['key']])) {
                continue;
            }
            $seen[$feature['key']] = true;
            $value = is_int($feature['value']) ? (float) $feature['value'] : $feature['value'];
            foreach ($feature['destinations'] as $destination) {
                if (!is_string($destination)) {
                    continue;
                }
                if (isset(self::$prefixes[$destination])) {
                    $metadata[self::$prefixes[$destination] . $feature['key']] = $value;
                } else {
                    self::warnUnknownDestination($destination);
                }
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, string|float|bool>
     */
    public static function withPrefix(array $metadata, $prefix)
    {
        $features = array();
        $length = strlen($prefix);
        foreach ($metadata as $key => $value) {
            if (is_string($key) && strncmp($key, $prefix, $length) === 0 && strlen($key) > $length) {
                $features[substr($key, $length)] = $value;
            }
        }

        return $features;
    }

    /** @internal Tests only. */
    public static function resetWarnedDestinations()
    {
        self::$warnedDestinations = array();
    }

    private static function warnUnknownDestination($destination)
    {
        if (isset(self::$warnedDestinations[$destination])
            || count(self::$warnedDestinations) >= self::WARNED_DESTINATIONS_LIMIT) {
            return;
        }
        self::$warnedDestinations[$destination] = true;
        try {
            (new NonThrowingLogger(Logger::get()))->warning(
                'Feature Flags: ignoring unknown split feature destination {destination}',
                array('destination' => $destination)
            );
        } catch (\Throwable $ignored) {
        }
    }
}
