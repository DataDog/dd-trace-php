<?php

namespace DDTrace\Tests\Unit\Util;

use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Util\Convention;
use function DDTrace\close_span;
use function DDTrace\start_span;

final class ConventionTest extends BaseTestCase
{
    protected function ddSetUp()
    {
        \dd_trace_serialize_closed_spans();
        parent::ddSetUp();
    }

    public function test_http_server_convention()
    {
        $span = start_span();
        $span->meta['http.request.method'] = 'GET';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_SERVER;

        $this->assertSame('http.server.request', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_http_client_convention()
    {
        $span = start_span();
        $span->meta['http.request.method'] = 'GET';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;

        $this->assertSame('http.client.request', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_database_convention()
    {
        $span = start_span();
        $span->meta['db.system'] = 'mysql';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;

        $this->assertSame('mysql.query', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_graphql_server_convention()
    {
        $span = start_span();
        $span->meta['graphql.operation.type'] = 'query';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_SERVER;

        $this->assertSame('graphql.server.request', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_rpc_server_convention()
    {
        $span = start_span();
        $span->meta['rpc.system'] = 'grpc';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_SERVER;

        $this->assertSame('grpc.server.request', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_rpc_client_convention()
    {
        $span = start_span();
        $span->meta['rpc.system'] = 'grpc';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;

        $this->assertSame('grpc.client.request', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_aws_client_convention()
    {
        $span = start_span();
        $span->meta['rpc.system'] = 'aws-api';
        $span->meta['rpc.service'] = 'DynamoDB';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;

        $this->assertSame('aws.dynamodb.request', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_message_consumer_convention()
    {
        $span = start_span();
        $span->meta['messaging.system'] = 'kafka';
        $span->meta['messaging.operation'] = 'receive';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CONSUMER;

        $this->assertSame('kafka.receive', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_message_client_convention()
    {
        $span = start_span();
        $span->meta['messaging.system'] = 'kafka';
        $span->meta['messaging.operation'] = 'send';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;

        $this->assertSame('kafka.send', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_message_producer_convention()
    {
        $span = start_span();
        $span->meta['messaging.system'] = 'kafka';
        $span->meta['messaging.operation'] = 'send';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_PRODUCER;

        $this->assertSame('kafka.send', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_faas_server_convention()
    {
        $span = start_span();
        $span->meta['faas.trigger'] = 'http';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_SERVER;

        $this->assertSame('http.invoke', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_faas_client_convention()
    {
        $span = start_span();
        $span->meta['faas.invoked_provider'] = 'aws';
        $span->meta['faas.invoked_name'] = 'lambda';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;

        $this->assertSame('aws.lambda.invoke', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_generic_server_convention()
    {
        $span = start_span();
        $span->meta['network.protocol.name'] = 'http';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_SERVER;

        $this->assertSame('http.server.request', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_generic_client_convention()
    {
        $span = start_span();
        $span->meta['network.protocol.name'] = 'http';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;

        $this->assertSame('http.client.request', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_generic_internal_convention()
    {
        $span = start_span();
        $span->meta['network.protocol.name'] = 'http';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_INTERNAL;

        $this->assertSame('http.internal.request', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_internal_convention_with_span_kind()
    {
        $span = start_span();
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_INTERNAL;

        $this->assertSame('internal.request', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_ot_unknown_convention()
    {
        $span = start_span();

        $this->assertSame('otel_unknown', Convention::defaultOperationName($span));

        close_span();
    }

    public function test_ot_unknown_with_tags()
    {
        $span = start_span();
        $span->meta['db.system'] = 'https';

        $this->assertSame('otel_unknown', Convention::defaultOperationName($span));

        close_span();
    }
}
