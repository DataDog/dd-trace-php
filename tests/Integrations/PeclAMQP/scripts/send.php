<?php

$connection = new \AMQPConnection([
    'host' => 'rabbitmq_integration',
    'port' => 5672,
    'login' => 'guest',
    'password' => 'guest',
]);
$connection->connect();

$channel = new \AMQPChannel($connection);

$exchange = new \AMQPExchange($channel);

try{
    $routing_key = 'hello';

    $queue = new AMQPQueue($channel);
    $queue->setName($routing_key);
    $queue->setFlags(AMQP_NOPARAM);
    $queue->declareQueue();


    $message = 'howdy-do';
    $exchange->publish($message, $routing_key);

    $connection->disconnect();
} catch(Exception $ex) {
    print_r($ex);
}
