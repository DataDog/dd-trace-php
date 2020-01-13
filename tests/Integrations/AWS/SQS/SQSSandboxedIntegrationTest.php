<?php

namespace DDTrace\Tests\Integrations\AWS\SQS;

use DDTrace\Tests\Common\IntegrationTestCase;

final class SQSSandboxedIntegrationTest extends IntegrationTestCase
{
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

        $result = $client->sendMessage($params);

        $result = $client->receiveMessage(array(
            'AttributeNames' => ['SentTimestamp'],
            'MaxNumberOfMessages' => 1,
            'MessageAttributeNames' => ['All'],
            'QueueUrl' => TestSQSClientSupport::QUEUE_URL,
            'WaitTimeSeconds' => 0,
        ));

        if (!empty($messages = $result->get('Messages'))) {
            foreach ($messages as $message) {
                $this->handleMessage($messages);
            }
        } else {
            error_log('No messages in queue');
        }
    }

    public function handleMessage(array $message)
    {
        error_log('Messages: ' . print_r($message, 1));
    }
}
