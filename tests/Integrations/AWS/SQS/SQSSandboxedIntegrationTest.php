<?php

namespace DDTrace\Tests\Integrations\AWS\SQS;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Integrations\CLI\CLITestCase;

final class SQSSandboxedIntegrationTest extends CLITestCase
{
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
        $params = [
            'DelaySeconds' => 0,
            'MessageAttributes' => [
                "title" => [
                    'DataType' => "String",
                    'StringValue' => "this is the title"
                ],
            ],
            'MessageBody' => "message body",
            'QueueUrl' => TestSQSClientSupport::QUEUE_URL,
        ];
        $client = TestSQSClientSupport::newInstance();
        TestSQSClientSupport::resetTestQueue($client);

        $result = $client->sendMessage($params);
        error_log('Sent messages: ' . print_r($result, 1));

        $traces = $this->getTracesFromCommand();
        error_log('Traces: ' . print_r($traces, 1));

        // $result = $client->receiveMessage(array(
        //     'AttributeNames' => ['SentTimestamp'],
        //     'MaxNumberOfMessages' => 1,
        //     'MessageAttributeNames' => ['All'],
        //     'QueueUrl' => TestSQSClientSupport::QUEUE_URL,
        //     'WaitTimeSeconds' => 1,
        // ));

        // if (!empty($messages = $result->get('Messages'))) {
        //     foreach ($messages as $message) {
        //         $this->handleMessage($messages);
        //     }
        // } else {
        //     error_log('No messages in queue');
        // }
    }

    public function handleMessage(array $message)
    {
        error_log('Messages: ' . print_r($message, 1));
    }
}
