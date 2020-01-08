<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;

class ExampleController extends BaseController
{
    public function example()
    {
        error_log('This is the example action');
        return "hi!";
    }
}
