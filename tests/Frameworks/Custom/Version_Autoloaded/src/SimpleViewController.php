<?php

namespace App;

class SimpleViewController
{
    public function render()
    {
        header('Content-type: text/html; charset=utf-8');
        echo '<h1>This is a simple view</h1>';
    }
}
