<?php

namespace DDTrace\Tests\Integrations\AMQP\V3_5;

error_reporting(E_ALL ^ E_DEPRECATED);  // AMQP2 will trigger deprecation warnings

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once $argv[1];

use PhpAmqpLib\Connection\AMQPStreamConnection;

$connection = new AMQPStreamConnection('rabbitmq_integration', 5672, 'guest', 'guest');
$channel = $connection->channel();

$channel->queue_declare('queue_scripts', false, false, false, false);

$callback = function ($msg) {
    if ($msg->has('application_headers')) {
        $headers = $msg->get('application_headers')->getNativeData();
        if (!isset($headers['Honored'])) {
            echo 'fail'; // User headers should not be lost
        }
    }
};

$channel->basic_consume('queue_scripts', '', false, true, false, false, $callback);

// Wait only for 1 message, then exit, but wait for 3 seconds for tests purposes
$channel->wait(null, false, 6);

$channel->close();
$connection->close();

error_reporting(E_ALL);
