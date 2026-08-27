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
        // replace this example code with whatever you need
        return new Response(
            'Hi!'
        );
    }

    #[Route("/dynamic-path/{param01}", locale: "en")]
    #[Route("/caminho-dinamico/{param01}", locale: "pt")]
    public function dynamicAction(Request $request, string $param01)
    {
        return new Response(
            "Hi $param01!"
        );
    }

    #[Route("/café/{item}", name: "utf8_route")]
    public function utf8Action(Request $request, string $item)
    {
        return new Response(
            "Café: $item"
        );
    }

    #[Route("/article/{slug}.{_format}", name: "article_mixed", requirements: ["_format" => "html|json|xml"])]
    public function normalizedMixedAction(Request $request, string $slug, string $_format)
    {
        return new Response(
            "$slug.$_format"
        );
    }
}
