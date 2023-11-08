<?php

namespace DDTrace\Tests\Unit\Util;

use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Util\Convention;
use OpenTelemetry\API\Trace\SpanKind;
use function DDTrace\close_span;
use function DDTrace\start_span;

final class ConventionTest extends BaseTestCase
{
    protected function ddSetUp()
    {
        \dd_trace_serialize_closed_spans();
        parent::ddSetUp();
    }

    public function providerConventionData()
    {
        /*
         * [
            ("http.server.request", SK_SERVER, {"http.request.method": "GET"}),
            ("http.client.request", SK_CLIENT, {"http.request.method": "GET"}),
            ("redis.query", SK_CLIENT, {"db.system": "Redis"}),
            ("kafka.receive", SK_CLIENT, {"messaging.system": "Kafka", "messaging.operation": "Receive"}),
            ("kafka.receive", SK_SERVER, {"messaging.system": "Kafka", "messaging.operation": "Receive"}),
            ("kafka.receive", SK_PRODUCER, {"messaging.system": "Kafka", "messaging.operation": "Receive"}),
            ("kafka.receive", SK_CONSUMER, {"messaging.system": "Kafka", "messaging.operation": "Receive"}),
            ("aws.s3.request", SK_CLIENT, {"rpc.system": "aws-api", "rpc.service": "S3"}),
            ("aws.client.request", SK_CLIENT, {"rpc.system": "aws-api"}),
            ("grpc.client.request", SK_CLIENT, {"rpc.system": "GRPC"}),
            ("grpc.server.request", SK_SERVER, {"rpc.system": "GRPC"}),
            ("aws.my-function.invoke", SK_CLIENT, {"faas.invoked_provider": "aws", "faas.invoked_name": "My-Function"}),
            ("datasource.invoke", SK_SERVER, {"faas.trigger": "Datasource"}),
            ("graphql.server.request", SK_SERVER, {"graphql.operation.type": "query"}),
            ("amqp.server.request", SK_SERVER, {"network.protocol.name": "Amqp"}),
            ("server.request", SK_SERVER, None),
            ("amqp.client.request", SK_CLIENT, {"network.protocol.name": "Amqp"}),
            ("client.request", SK_CLIENT, None),
            ("internal", SK_INTERNAL, None),
            ("consumer", SK_CONSUMER, None),
            ("producer", SK_PRODUCER, None),
            ("otel_unknown", None, None),
        ],
         */

        return [
            ['http.server.request',     Tag::SPAN_KIND_VALUE_SERVER,        ['http.request.method' => 'GET']],
            ['http.client.request',     Tag::SPAN_KIND_VALUE_CLIENT,        ['http.request.method' => 'GET']],
            ['redis.query',             Tag::SPAN_KIND_VALUE_CLIENT,        ['db.system' => 'Redis']],
            ['kafka.receive',           Tag::SPAN_KIND_VALUE_CLIENT,        ['messaging.system' => 'Kafka', 'messaging.operation' => 'Receive']],
            ['kafka.receive',           Tag::SPAN_KIND_VALUE_SERVER,        ['messaging.system' => 'Kafka', 'messaging.operation' => 'Receive']],
            ['kafka.receive',           Tag::SPAN_KIND_VALUE_PRODUCER,      ['messaging.system' => 'Kafka', 'messaging.operation' => 'Receive']],
            ['kafka.receive',           Tag::SPAN_KIND_VALUE_CONSUMER,      ['messaging.system' => 'Kafka', 'messaging.operation' => 'Receive']],
            ['aws.s3.request',          Tag::SPAN_KIND_VALUE_CLIENT,        ['rpc.system' => 'aws-api', 'rpc.service' => 'S3']],
            ['aws.client.request',      Tag::SPAN_KIND_VALUE_CLIENT,        ['rpc.system' => 'aws-api']],
            ['grpc.client.request',     Tag::SPAN_KIND_VALUE_CLIENT,        ['rpc.system' => 'GRPC']],
            ['grpc.server.request',     Tag::SPAN_KIND_VALUE_SERVER,        ['rpc.system' => 'GRPC']],
            ['aws.my-function.invoke',  Tag::SPAN_KIND_VALUE_CLIENT,        ['faas.invoked_provider' => 'aws', 'faas.invoked_name' => 'My-Function']],
            ['datasource.invoke',       Tag::SPAN_KIND_VALUE_SERVER,        ['faas.trigger' => 'Datasource']],
            ['graphql.server.request',  Tag::SPAN_KIND_VALUE_SERVER,        ['graphql.operation.type' => 'query']],
            ['amqp.server.request',     Tag::SPAN_KIND_VALUE_SERVER,        ['network.protocol.name' => 'Amqp']],
            ['server.request',          Tag::SPAN_KIND_VALUE_SERVER,        []],
            ['amqp.client.request',     Tag::SPAN_KIND_VALUE_CLIENT,        ['network.protocol.name' => 'Amqp']],
            ['client.request',          Tag::SPAN_KIND_VALUE_CLIENT,        []],
            ['internal',                Tag::SPAN_KIND_VALUE_INTERNAL,      []],
            ['consumer',                Tag::SPAN_KIND_VALUE_CONSUMER,      []],
            ['producer',                Tag::SPAN_KIND_VALUE_PRODUCER,      []],
            ['otel_unknown',            null,                               []]
        ];
    }

    /**
     * @dataProvider providerConventionData
     */
    public function testConvention($expectedOperationName, $spanKind, $attributes)
    {
        $span = start_span();
        $span->meta = $attributes;
        if ($spanKind !== null) {
            $span->meta[Tag::SPAN_KIND] = $spanKind;
        }

        $this->assertSame($expectedOperationName, Convention::defaultOperationName($span));

        close_span();
    }
}
