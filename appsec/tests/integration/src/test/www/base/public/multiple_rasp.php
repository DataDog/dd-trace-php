<?php

$url = 'http://'. $_GET['domain'] .'/somewhere/in/the/app';
$path = $_GET['path'];
$other = $_GET['other'];

$context = stream_context_create([
    'http' => [
        'timeout' => 0.2, // 200 milliseconds
    ]
]);

// LFI with match
@fopen($path, 'r');
// SSRF with match
@fopen($url, 'r', false, $context);
// SSRF without match
@fopen('http://example.com', 'r', false, $context);
// LFI without match
@fopen('example.html', 'r');
// LFI with match
@fopen($other, 'r');

echo "OK";