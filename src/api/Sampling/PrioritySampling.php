<?php

namespace DDTrace\Sampling;

class PrioritySampling
{
    // The Agent will drop the trace, as instructed by any mechanism that is not the sampler.
    const USER_REJECT = -1;

    // Automatic sampling decision. The Agent should drop the trace.
    const AUTO_REJECT = 0;

    // Automatic sampling decision. The Agent should keep the trace.
    const AUTO_KEEP = 1;

    // The Agent should keep the trace, as instructed by any mechanism that is not the sampler.
    // The backend will only apply sampling if above maximum volume allowed.
    const USER_KEEP = 2;

    // It was not possible to parse
    const UNKNOWN = null;

    /**
     * @param mixed|string $value
     * @return int|null
     */
    public static function parse($value)
    {
        if (!is_numeric($value)) {
            return self::UNKNOWN;
        }

        $intValue = intval($value);
        return in_array($intValue, [self::USER_REJECT, self::AUTO_KEEP, self::AUTO_REJECT, self::USER_KEEP])
            ? $intValue
            : self::UNKNOWN;
    }
}
