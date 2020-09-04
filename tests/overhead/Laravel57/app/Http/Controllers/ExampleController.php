<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Routing\Controller as BaseController;

class ExampleController extends BaseController
{
    public function example()
    {
        error_log('This is the example action');
        return "Hi from Laravel 5.7 app!\n";
    }

    public function exception()
    {
        throw new \Exception('fake exceptino generated!');
    }

    public function fatal()
    {
        new ClassNotExist();
    }

    public function trigger_error()
    {
        trigger_error("Some fatal error", E_USER_ERROR);
    }
}
