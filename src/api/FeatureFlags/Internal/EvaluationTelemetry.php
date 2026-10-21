<?php

namespace DDTrace\FeatureFlags\Internal;

/** @internal Admission policy for new evaluation telemetry only. */
final class EvaluationTelemetry
{
    const OPT_OUT_ATTRIBUTE = 'dd_evaluation_telemetry_opt_out';

    public static function shouldRecord($enableOptOutParameter, array $attributes)
    {
        return !$enableOptOutParameter
            || !isset($attributes[self::OPT_OUT_ATTRIBUTE])
            || $attributes[self::OPT_OUT_ATTRIBUTE] !== true;
    }
}
