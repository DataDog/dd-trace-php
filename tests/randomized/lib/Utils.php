<?php

namespace RandomizedTests\Tooling;

class Utils
{
    /**
     * Returns true $percent of times this function is invoked. Otherwise false.
     *
     * @param int $percent
     * @return bool
     */
    public static function percentOfTimes($percent)
    {
        return rand(0, 100) <= $percent;
    }
}
