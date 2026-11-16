<?php

namespace DDTrace\Tests\Integrations\AMQP\Latest;

use DDTrace\Integrations\AMQP\AMQPIntegration;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPBufferReader;

class AMQPTest extends \DDTrace\Tests\Integrations\AMQP\V2\AMQPTest
{
    /**
     * php-amqplib 3 decodes the floating point headers other clients send, e.g. the Java and
     * Node.js ones for fractional numbers, but can't encode them. The checkpoint sizes them anyway.
     */
    public function testDataStreamsConsumeCheckpointSizesDoubleHeaders()
    {
        self::putEnvAndReloadConfig(['DD_DATA_STREAMS_ENABLED=true']);
        $entry = chr(11) . 'retry-delay' . 'd' . pack('E', 1.5);
        $headers = (new AMQPBufferReader(pack('N', strlen($entry)) . $entry))->read_table_object();
        $this->assertSame(['retry-delay' => 1.5], $headers->getNativeData());

        // Throws if sizing the headers does.
        \DDTrace\Internal\dsm_push_pathway();
        try {
            AMQPIntegration::setDataStreamsConsumeCheckpoint(
                new AMQPMessage('order', ['application_headers' => $headers]),
                'dsm_orders'
            );
        } finally {
            \DDTrace\Internal\dsm_pop_pathway();
        }
        $this->assertSame(strlen($entry), self::dsmHeadersSize($headers->getNativeData()));
    }
}
