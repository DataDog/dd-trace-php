<?php

declare(strict_types=1);

namespace App\Controller;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController
{
    /**
     * @Route("/notSwallowed", name="notSwallowed")
     */
    public function index()
    {
        $a = null;
        $a->hello();
    }
}
