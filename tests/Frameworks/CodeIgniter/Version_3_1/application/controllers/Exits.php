<?php

use CI_Controller;

class Exits extends CI_Controller
{
    function index()
    {
        echo "Exiting.\n";
        exit();
    }
}

