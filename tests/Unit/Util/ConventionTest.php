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

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('http.server.request', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_http_client_convention()
    {
        $span = start_span();
        $span->meta['http.request.method'] = 'GET';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('http.client.request', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_database_convention()
    {
        $span = start_span();
        $span->meta['db.system'] = 'mysql';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('mysql.query', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_graphql_server_convention()
    {
        $span = start_span();
        $span->meta['graphql.operation.type'] = 'query';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_SERVER;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('graphql.server.request', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_rpc_server_convention()
    {
        $span = start_span();
        $span->meta['rpc.system'] = 'grpc';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_SERVER;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('grpc.server.request', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_rpc_client_convention()
    {
        $span = start_span();
        $span->meta['rpc.system'] = 'grpc';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('grpc.client.request', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_aws_client_convention()
    {
        $span = start_span();
        $span->meta['rpc.system'] = 'aws-api';
        $span->meta['rpc.service'] = 'DynamoDB';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('aws.dynamodb.request', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_message_consumer_convention()
    {
        $span = start_span();
        $span->meta['messaging.system'] = 'kafka';
        $span->meta['messaging.operation'] = 'receive';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CONSUMER;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('kafka.receive', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_message_client_convention()
    {
        $span = start_span();
        $span->meta['messaging.system'] = 'kafka';
        $span->meta['messaging.operation'] = 'send';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('kafka.send', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_message_producer_convention()
    {
        $span = start_span();
        $span->meta['messaging.system'] = 'kafka';
        $span->meta['messaging.operation'] = 'send';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_PRODUCER;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('kafka.send', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_faas_server_convention()
    {
        $span = start_span();
        $span->meta['faas.trigger'] = 'http';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_SERVER;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('http.invoke', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_faas_client_convention()
    {
        $span = start_span();
        $span->meta['faas.invoked_provider'] = 'aws';
        $span->meta['faas.invoked_name'] = 'lambda';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('aws.lambda.invoke', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_generic_server_convention()
    {
        $span = start_span();
        $span->meta['network.protocol.name'] = 'http';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_SERVER;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('http.server.request', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_generic_client_convention()
    {
        $span = start_span();
        $span->meta['network.protocol.name'] = 'http';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('http.client.request', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_generic_internal_convention()
    {
        $span = start_span();
        $span->meta['network.protocol.name'] = 'http';
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_INTERNAL;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('http.internal.request', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_internal_convention_with_span_kind()
    {
        $span = start_span();
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_INTERNAL;

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('internal.request', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_ot_unknown_convention()
    {
        $span = start_span();

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('otel_unknown', $spanConvention->defaultOperationName($span));

        close_span();
    }

    public function test_ot_unknown_with_tags()
    {
        $span = start_span();
        $span->meta['db.system'] = 'https';

        $spanConvention = Convention::conventionOf($span);
        $this->assertSame('otel_unknown', $spanConvention->defaultOperationName($span));

        close_span();
    }
}
