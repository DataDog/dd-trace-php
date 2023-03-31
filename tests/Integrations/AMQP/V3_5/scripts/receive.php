<?php
namespace DDTrace\Tests\Integrations\AMQP\V3_5;

require_once __DIR__ . '/../../../../vendor/autoload.php';


use PhpAmqpLib\Connection\AMQPStreamConnection;

$connection = new AMQPStreamConnection('rabbitmq_integration', 5672, 'guest', 'guest');
$channel = $connection->channel();

$channel->queue_declare('queue_scripts', false, false, false, false);

$callback = function ($msg) {
    file_put_contents('/tmp/amqp.log', ' [x] Received ' . $msg->body . "\n", FILE_APPEND);
    fwrite(STDERR, ' [x] Received ' . $msg->body . "\n");
    echo ' [x] Received ', $msg->body, "\n";

    if ($msg->has('application_headers')) {
        $headers = $msg->get('application_headers')->getNativeData();
        if (isset($headers['x-datadog-trace-id'])) {
            fwrite(STDERR, ' [x] Received trace id ' . $headers['x-datadog-trace-id'] . "\n");
            echo ' [x] Received trace id ', $headers['x-datadog-trace-id'], "\n";
        }
        if (isset($headers['x-datadog-parent-id'])) {
            fwrite(STDERR, ' [x] Received parent id ' . $headers['x-datadog-parent-id'] . "\n");
            echo ' [x] Received parent id ', $headers['x-datadog-parent-id'], "\n";
        }
    } else {
        fwrite(STDERR, ' [x] Received no headers' . "\n");
        echo ' [x] Received no headers', "\n";
    }
};

$channel->basic_consume('queue_scripts', '', false, true, false, false, $callback);

// Wait only for 1 message, then exit, but wait for 3 seconds for tests purposes
$channel->wait(null, false, 6);

$channel->close();
$connection->close();
