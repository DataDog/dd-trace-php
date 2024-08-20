<?php

require __DIR__ . '/../Predis/vendor/autoload.php';

// This script HAS to invoke at least two integrations via deferred integration loading mechanism.

// PDO
const MYSQL_HOST = 'mysql_integration';
const MYSQL_USER = 'test';
const MYSQL_PASSWORD = 'test';
const MYSQL_DATABASE = 'test';

$pdo = new \PDO(
    sprintf("mysql:host=%s;dbname=%s", MYSQL_HOST, MYSQL_DATABASE),
    MYSQL_USER,
    MYSQL_PASSWORD
);
$stmt = $pdo->prepare('SELECT 1');
$stmt->execute();

// Predis
const PREDIS_HOST = 'redis_integration';
const PREDIS_PORT = '6379';
$client = new \Predis\Client(["host" => PREDIS_HOST]);
$client->set('key', 'value');

echo "OK";
