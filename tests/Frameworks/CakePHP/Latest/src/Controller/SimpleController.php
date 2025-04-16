<?php

namespace App\Controller;

class SimpleController extends AppController
{
    public function index()
    {
        $this->autoRender = false;
        echo 'Hello.';
    }
}
