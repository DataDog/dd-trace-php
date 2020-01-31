<?php

namespace DDTrace\Tests\Integrations\AWS\SQS;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Integrations\CLI\CLITestCase;

final class SQSSandboxedIntegrationTest extends CLITestCase
{
    use TracerTestTrait;

    protected function getScriptLocation()
    {
        return __DIR__ . '/scripts/consume_sync.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_APP_NAME' => 'sqs_test_app',
            'DD_TRACE_DEBUG' => 'true',
        ]);
    }

    protected static function getInis()
    {
        return \array_merge(parent::getInis(), [
            'error_log' => __DIR__ . '/scripts/error.log',
        ]);
    }

    public function testReceiveOneMessage()
    {
        $client = TestSQSClientSupport::newInstance();
        TestSQSClientSupport::resetTestQueue($client);
        $client->sendMessage($this->sampleMessage());
        $traces = $this->getTracesFromCommand();

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'my_operation',
                'my_service',
                'custom',
                'some message'
            )->withChildren([
                SpanAssertion::exists('curl_exec'),
                SpanAssertion::exists('mysqli_connect'),
            ]),
        ]);
    }

    public function testReceiveTwoMessages()
    {
        $client = TestSQSClientSupport::newInstance();
        TestSQSClientSupport::resetTestQueue($client);
        $client->sendMessage($this->sampleMessage("message 1"));
        $client->sendMessage($this->sampleMessage("message 2"));
        $traces = $this->getTracesFromCommand();
        error_log('Traces: ' . print_r($traces, 1));

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'my_operation',
                'my_service',
                'custom',
                'message 1'
            )->withChildren([
                SpanAssertion::exists('curl_exec'),
                SpanAssertion::exists('mysqli_connect'),
            ]),
            SpanAssertion::build(
                'my_operation',
                'my_service',
                'custom',
                'message 2'
            )->withChildren([
                SpanAssertion::exists('curl_exec'),
                SpanAssertion::exists('mysqli_connect'),
            ]),
        ]);
    }

    private function sampleMessage($resource = "some message")
    {
        return [
            'DelaySeconds' => 0,
            'MessageAttributes' => [
                "title" => [
                    'DataType' => "String",
                    'StringValue' => $resource
                ],
            ],
            'MessageBody' => "message body",
            'QueueUrl' => TestSQSClientSupport::QUEUE_URL,
        ];
    }
}
