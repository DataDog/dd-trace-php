<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    public function example()
    {
        return "Hi from Laravel " . Application::VERSION . " app on PHP " . \phpversion() . "!\n";
    }
}
