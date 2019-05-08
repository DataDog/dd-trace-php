<?php

namespace App\Http\Controllers;

class ExampleController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    public function simple()
    {
        return 'simple';
    }

    public function simpleView()
    {
        return view('simple_view');
    }

    public function error()
    {
        throw new \Exception('Controller error');
    }
}
