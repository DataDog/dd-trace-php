<?php

namespace DDTrace\FeatureFlags;

final class ExperimentationOptOutMode
{
    const DISABLED = 'disabled';
    const IMMEDIATE = 'immediate';

    private function __construct()
    {
    }

    /**
     * A null override uses the SDK configuration. Explicit values take precedence.
     * @internal
     */
    public static function resolve($override = null)
    {
        $mode = $override;
        if ($mode === null) {
            $mode = \function_exists('dd_trace_env_config')
                ? \dd_trace_env_config('DD_FEATURE_FLAGS_EXPERIMENTATION_OPT_OUT_MODE')
                : self::DISABLED;
            // Older extensions return false for an unknown configuration key.
            if ($mode === false || $mode === null) {
                $mode = self::DISABLED;
            }
        }
        if (!is_string($mode)) {
            throw new \InvalidArgumentException('Experimentation opt-out mode must be disabled or immediate');
        }
        $mode = strtolower(trim($mode));
        if ($mode === '') {
            return self::DISABLED;
        }
        if ($mode !== self::DISABLED && $mode !== self::IMMEDIATE) {
            throw new \InvalidArgumentException('Experimentation opt-out mode must be disabled or immediate');
        }
        return $mode;
    }
}
