<?php

require __DIR__ . '/../../vendor/autoload.php';

$function = $_GET['function'];
$path = $_GET['path'];

switch ($function) {
    case 'file_put_contents':
            file_put_contents($path, 'some content');
            break;
    case 'fopen':
            fopen($path, 'r');
            break;
    default:
        $function($path);
        break;
}

echo "OK";
