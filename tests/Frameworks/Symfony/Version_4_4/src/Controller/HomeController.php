<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends AbstractController
{
    /**
     * @Route("/", name="home")
     */
    public function homeAction(Request $request)
    {
        return new Response('Hi!');
    }

    /**
     * @Route("/dynamic-path/{param01}", name="app_home_dynamic")
     */
    public function dynamicAction(Request $request, string $param01)
    {
        return new Response("Hi $param01!");
    }

    /**
     * Symfony 4.x's RouteCompiler requires `options.utf8=true` to be set
     * explicitly whenever a route path contains non-ASCII characters.
     * (Symfony 5+ auto-detects the flag from the path.)
     *
     * @Route("/café/{item}", name="utf8_route", options={"utf8"=true})
     */
    public function utf8Action(Request $request, string $item)
    {
        return new Response("Café: $item");
    }
}
