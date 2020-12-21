<?php

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

// Memcached
const MEMCACHED_HOST = 'memcached_integration';
const MEMCACHED_PORT = '11211';
$client = new \Memcached();
$client->addServer(MEMCACHED_HOST, MEMCACHED_PORT);
$client->add('key', 'value');

echo "OK";
