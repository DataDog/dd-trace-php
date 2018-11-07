<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


class SimpleController extends AbstractController
{
    /**
     * @Route("/simple", name="simple")
     */
    public function simple()
    {
        return new Response('simple');
    }

    /**
     * @Route("/simple_view", name="simple_view")
     */
    public function simple_view()
    {
        return $this->render('simple_view.html.twig', []);
    }
}
