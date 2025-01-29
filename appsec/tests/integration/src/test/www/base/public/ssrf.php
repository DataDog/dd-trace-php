<?php

function one() {
    $function = $_GET['function'];
    $path = 'http://'. $_GET['domain'] .'/somewhere/in/the/app';

    switch ($function) {
        case 'fopen':
                fopen($path, 'r');
                break;
        default:
            $function($path);
            break;
    }
}

function two() {
    one();
}

function three() {
    two();
}

three();


echo "OK";