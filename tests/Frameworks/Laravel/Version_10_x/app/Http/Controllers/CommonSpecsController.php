<?php

namespace App\Http\Controllers;

use App\Exceptions\IgnoredException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    public function ignored_exception()
    {
        throw new HttpException(408, "Sample Exception");
    }
}
