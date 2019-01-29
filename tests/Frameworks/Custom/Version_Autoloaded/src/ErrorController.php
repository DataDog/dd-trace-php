<?php

namespace App;

class ErrorController
{
    public function render()
    {
        header('HTTP/1.0 500 Internal Server Error');
        header('Content-type: text/html; charset=utf-8');
        echo '<h1 style="color:#fff; background: #f00;">Error with all the things!</h1>';
    }
}
