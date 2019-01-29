<?php

namespace App;

class SimpleController
{
    public function render()
    {
        header('Content-type: text/plain; charset=utf-8');
        echo 'This is a string';
    }
}
