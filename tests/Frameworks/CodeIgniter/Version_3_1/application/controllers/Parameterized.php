<?php

use CI_Controller;

class Parameterized extends CI_Controller
{
    function customAction($param)
    {
        echo 'custom ' . $param;
    }
}
