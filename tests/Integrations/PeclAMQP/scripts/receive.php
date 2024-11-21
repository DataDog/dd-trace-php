<?php

$connection = new \AMQPConnection([
    'host' => 'rabbitmq_integration',
    'port' => 5672,
    'login' => 'guest',
    'password' => 'guest',
    'read_timeout' => 1,
]);
$connection->connect();

$channel = new AMQPChannel($connection);

$exchange = new AMQPExchange($channel);

$consumeOne = true; // Flag to control message consumption

$callback_func = function(AMQPEnvelope $message, AMQPQueue $q) use (&$consumeOne) {
    echo PHP_EOL, "------------", PHP_EOL;
    echo " [x] Received ", $message->getBody(), PHP_EOL;
    echo PHP_EOL, "------------", PHP_EOL;

    // Acknowledge the message
    $q->ack($message->getDeliveryTag());

    // Set the flag to false after consuming the message
    $consumeOne = false;
};

try {
    $routing_key = 'hello';

    $queue = new AMQPQueue($channel);
    $queue->setName($routing_key);
    $queue->setFlags(AMQP_NOPARAM);
    $queue->declareQueue();

    echo ' [*] Waiting for messages. To exit press CTRL+C ', PHP_EOL;

    while ($consumeOne) {
        // Consume a single message, with a timeout to avoid infinite loop
        $queue->consume($callback_func, AMQP_NOPARAM);
    }
} catch (AMQPQueueException $ex) {
    print_r($ex);
} catch (Exception $ex) {
    print_r($ex);
}

echo 'Close connection...', PHP_EOL;
$connection->disconnect();
