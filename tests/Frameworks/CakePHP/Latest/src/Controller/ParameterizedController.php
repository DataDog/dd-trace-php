<?php

namespace App\Controller;

class ParameterizedController extends AppController
{
    public function customAction($param)
    {
        $this->autoRender = false;
        echo "Hello $param";
    }
}
