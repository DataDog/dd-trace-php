<?php

$url = 'http://'. $_GET['domain'] .'/somewhere/in/the/app';
$path = $_GET['path'];
$other = $_GET['other'];

// LFI with match
@fopen($path, 'r');
// SSRF with match
@fopen($url, 'r');
// SSRF without match
@fopen('http://example.com', 'r');
// LFI without match
@fopen('example.html', 'r');
// LFI with match
@fopen($other, 'r');

echo "OK";