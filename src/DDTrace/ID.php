<?php

namespace DDTrace;

final class ID
{
    /**
     * Generates a new random 63bits ID between 1 and PHP_INT_MAX
     *
     * @return string
     */
    public static function generate()
    {
        /*
         * Trace and span ID's need to be unsigned-63-bit-int strings in
         * order to work well with other APM integrations. Since the tracer
         * is not in a cryptographic context, we don't need to use PHP's
         * CSPRNG random_bytes(); instead the more performant mt_rand()
         * will do. And since all integers in PHP are signed, an int
         * between 1 & PHP_INT_MAX will be 63-bit.
         */
        $first31bits = mt_rand(0, mt_getrandmax()) << 32;
        $second31bits = mt_rand(0, mt_getrandmax()) << 1;
        $random1or0 = mt_rand(0, 1);
        return (string) ($first31bits | $second31bits | $random1or0);
    }

    /**
     * The expected max value for the generated id.
     *
     * @return int
     */
    public static function getMaxId()
    {
        return (mt_getrandmax() << 32) | (mt_getrandmax() << 1) | 1;
    }
}
