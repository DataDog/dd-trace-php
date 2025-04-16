<?php

$context = stream_context_create([
    'http' => [
        'timeout' => 0.2, // 200 milliseconds
    ]
]);

function one()
{
    $function = $_GET['function'];
    $path = 'http://'. $_GET['domain'] .'/somewhere/in/the/app';

    switch ($function) {
        case 'fopen':
                fopen($path, 'r', false, $GLOBALS['context']);
            break;
        case 'file_get_contents':
                file_get_contents($path, false, $GLOBALS['context']);
            break;
        default:
            $function($path);
            break;
    }
}

function two()
{
    one();
}

function three()
{
    two();
}

three();


echo "OK";
