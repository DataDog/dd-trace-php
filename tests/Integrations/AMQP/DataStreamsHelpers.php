<?php

namespace DDTrace\Tests\Integrations\AMQP;

/**
 * Data Streams Monitoring helpers shared by the php-amqplib and ext-amqp tests.
 */
trait DataStreamsHelpers
{
    private static function dsmHash($context)
    {
        $bytes = base64_decode($context, true);
        return sprintf('%u', unpack('P', substr($bytes, 0, 8))[1]);
    }

    /**
     * The hash the last of $checkpoints ([edge tags, context the message arrived with]) gets on
     * a fresh pathway. The hashes themselves are checked against system-tests' in
     * components-rs/data_streams.rs; these tests check the tags, the contexts and the parents.
     */
    private static function dsmHashOnFreshPathway(array $checkpoints)
    {
        \DDTrace\Internal\dsm_push_pathway();
        try {
            foreach ($checkpoints as list($edgeTags, $context)) {
                $last = \DDTrace\Internal\dsm_set_checkpoint($edgeTags, $context);
            }
        } finally {
            \DDTrace\Internal\dsm_pop_pathway();
        }
        return self::dsmHash($last);
    }
}
