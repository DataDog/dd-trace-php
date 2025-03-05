<?php

use Illuminate\Routing\Controller as BaseController;

class RaspTestController extends BaseController
{
    public function rasp()
    {
        file_get_contents($_REQUEST["data"]);

        return 'Rasp page';
    }
}
