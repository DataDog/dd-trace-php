<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Routing\Controller as BaseController;

class ResourceController extends BaseController
{
    public function index()
    {
        throw new \Exception('exception from resource controller');
    }
}
