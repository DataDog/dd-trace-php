<?php
$content = 'Hello world!';
header('Content-Length: ' . strlen($content));
header('Content-Type: text/plain');
echo $content;
