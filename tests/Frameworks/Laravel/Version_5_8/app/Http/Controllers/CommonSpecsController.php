<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;

class CommonSpecsController extends BaseController
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
}
