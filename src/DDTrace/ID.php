<?php

namespace DDTrace;

final class ID
{
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
        return (string) mt_rand(1, PHP_INT_MAX);
    }
}
