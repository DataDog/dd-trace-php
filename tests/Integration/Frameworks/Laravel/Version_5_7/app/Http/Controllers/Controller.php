<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;


class Controller extends BaseController
{
    public function simple()
    {
        return "simple";
    }

    public function simple_view()
    {
        return view("simple_view");
    }
}
