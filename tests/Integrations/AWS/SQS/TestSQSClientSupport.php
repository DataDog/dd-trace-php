<?php

namespace DDTrace\Tests\Integrations\AWS\SQS;

use Aws\Sqs\SqsClient;

final class TestSQSClientSupport
{
    const QUEUE_NAME = 'DD_TEST_QUEUE';
    const QUEUE_URL = 'http://aws_integration:4576/queue/DD_TEST_QUEUE';

    /**
     * @return SqsClient
     */
    public static function newInstance()
    {
        self::setupCredentials();
        $client = new SqsClient([
            'profile' => 'default',
            'region' => 'us-west-2',
            'version' => '2012-11-05',
            'endpoint' => 'http://aws_integration:4576',
        ]);
        self::resetTestQueue($client);
        return $client;
    }

    private static function resetTestQueue(SqsClient $client)
    {
        $queueExists = false;
        try {
            $result = $client->getQueueUrl(['QueueName' => self::QUEUE_NAME]);
            $queueExists = true;
        } catch (\Exception $ex) {
            // Note: if you see connection refused in CI it might be because localstack takes a while to
            // be up and running.
        }

        if ($queueExists) {
            $client->deleteQueue(['QueueUrl' => self::QUEUE_URL]);
            //\sleep(1);
        }

        $client->createQueue([
            'QueueName' => self::QUEUE_NAME,
            'Attributes' => array(
                'DelaySeconds' => 0,
                'MaximumMessageSize' => 4096, // 4 KB
            ),
        ]);
    }

    private static function setupCredentials()
    {
        $path = getenv("HOME") . "/.aws";
        if (!is_dir($path)) {
            mkdir($path);
        }
        file_put_contents(
            "$path/credentials",
            "[default]\naws_access_key_id=abc\naws_secret_access_key=def"
        );
    }
}
