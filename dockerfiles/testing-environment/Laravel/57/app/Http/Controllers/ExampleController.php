<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;

class ExampleController extends BaseController
{
    public function example()
    {
        return "Hi from Laravel " . Application::VERSION . " app on PHP " . \phpversion() . "!\n";
    }
}
