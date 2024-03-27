<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class MiscController extends Controller
{
    public function dynamicPath(string $param01)
    {
        return response('Hi', 200);
    }
}
