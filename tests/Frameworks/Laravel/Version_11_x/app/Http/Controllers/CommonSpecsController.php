<?php

namespace App\Http\Controllers;

use App\Exceptions\CustomException;

class CommonSpecsController extends Controller
{
    public function simple()
    {
        return "simple";
    }

    public function simple_view()
    {
        return view("simple_view");
    }

    public function error()
    {
        throw new \Exception('Controller error');
    }

    public function custom_exception()
    {
        throw new CustomException('An error message');
    }
}
