<?php

namespace DDTrace\Tests\Integrations\AWS;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\Sqs\SqsClient;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use Exception;

final class CommonScenariosTest extends IntegrationTestCase
{
    const QUEUE_NAME = 'DD_TEST_QUEUE';
    const QUEUE_URL = 'http://aws_integration:4576/queue/DD_TEST_QUEUE';

    /** @var SqsClient */
    private $client;

    protected function setUp()
    {
        parent::setUp();
        $this->setupCredentials();
        $this->client = new SqsClient([
            'profile' => 'default',
            'region' => 'us-west-2',
            'version' => '2012-11-05',
            'endpoint' => 'http://aws_integration:4576',
        ]);

        $queueExists = false;
        try {
            $result = $this->client->getQueueUrl(['QueueName' => self::QUEUE_NAME]);
            $queueExists = true;
        } catch (\Exception $ex) {
            // Note: if you see connection refused in CI it might be because localstack takes a while to
            // be up and running.
        }

        if ($queueExists) {
            $this->client->deleteQueue(['QueueUrl' => self::QUEUE_URL]);
            //\sleep(1);
        }

        $this->client->createQueue([
            'QueueName' => self::QUEUE_NAME,
            'Attributes' => array(
                'DelaySeconds' => 0,
                'MaximumMessageSize' => 4096, // 4 KB
            ),
        ]);

        //\sleep(1);
    }

    protected function tearDown()
    {
        parent::tearDown();
    }

    public function test1()
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
            'QueueUrl' => self::QUEUE_URL,
        ];

        $result = $this->client->sendMessage($params);

        $result = $this->client->receiveMessage(array(
            'AttributeNames' => ['SentTimestamp'],
            'MaxNumberOfMessages' => 1,
            'MessageAttributeNames' => ['All'],
            'QueueUrl' => self::QUEUE_URL,
            'WaitTimeSeconds' => 0,
        ));

        if (!empty($result->get('Messages'))) {
            error_log('Messages: ' . print_r($result->get('Messages'), 1));
        } else {
            error_log('No messages in queue');
        }
    }

    private function setupCredentials()
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
