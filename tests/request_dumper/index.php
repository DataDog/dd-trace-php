<?php

$requestLog = '/tmp/dump/dump.json';

$requests = file_exists($requestLog) ? json_decode(file_get_contents($requestLog), true) : [];

$requests[] = [
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => getallheaders(),
    'body' => file_get_contents('php://input'),
];

file_put_contents($requestLog, json_encode($requests));
