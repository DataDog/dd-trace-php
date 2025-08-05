<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller;

class RaspTestController extends Controller
{
    public function rasp()
    {
        file_get_contents($_REQUEST["data"]);

        return 'Rasp page';
    }
}
