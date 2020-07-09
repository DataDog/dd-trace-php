<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;

class ExampleController extends BaseController
{
    public function example()
    {
        return "Hi from Laravel 5.7 app!\n";
    }
}
