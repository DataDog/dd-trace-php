<?php

$requestLog = '/tmp/dump/dump.json';

file_put_contents($requestLog, json_encode([
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => getallheaders(),
    'body' => file_get_contents('php://input'),
]));
