<?php

namespace DDTrace\Tests\Integrations\AMQP\V3_5;

error_reporting(E_ALL ^ E_DEPRECATED);  // AMQP2 will trigger deprecation warnings

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once $argv[1];

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

$connection = new AMQPStreamConnection('rabbitmq_integration', 5672, 'guest', 'guest');
$channel = $connection->channel();

$channel->queue_declare('queue_scripts', false, false, false, false);

$msg = new AMQPMessage(
    'Hello World!',
    ['application_headers' => new AMQPTable(['Honored' => 'preserved_value'])]
);
$channel->basic_publish($msg, '', 'queue_scripts');

echo " [x] Sent 'Hello World!'\n";

$channel->close();
$connection->close();

error_reporting(E_ALL);
