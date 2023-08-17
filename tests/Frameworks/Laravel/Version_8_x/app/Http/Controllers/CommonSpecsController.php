<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

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

    public function dynamicRoute(Request $request, $param01, $param02 = 'defaultValue')
    {
        return "dynamicRoute";
    }
}
