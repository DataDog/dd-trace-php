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

    #[Route("/posts/{page}", name: "posts_optional_page", defaults: ["page" => 1])]
    public function postsAction(Request $request, int $page)
    {
        return new Response("posts page: $page");
    }

    #[Route("/normalized/mixed/{id}.{_format}", name: "normalized_mixed")]
    public function normalizedMixedIdAction(Request $request)
    {
        return new Response('Mixed route');
    }

    #[Route("/normalized/zero/{id}", name: "normalized_zero")]
    public function normalizedZeroAction(Request $request)
    {
        return new Response('Zero route');
    }

    #[Route(
        "/normalized/search.{_format}",
        name: "normalized_static_optional",
        defaults: ["_format" => null]
    )]
    public function normalizedStaticOptionalAction(Request $request)
    {
        return new Response('Optional format route');
    }

    #[Route(
        "/normalized/utf8/{föo}",
        name: "normalized_utf8_optional",
        defaults: ["föo" => null]
    )]
    public function normalizedUtf8OptionalAction(Request $request)
    {
        return new Response('UTF-8 parameter route');
    }

    #[Route(
        "/normalized/ambiguous/{slug}.{format}",
        name: "normalized_ambiguous_mixed",
        defaults: ["format" => "html"],
        requirements: ["slug" => ".+", "format" => "html|json"]
    )]
    public function normalizedAmbiguousMixedAction(
        Request $request,
        string $slug,
        string $format
    )
    {
        return new Response("Ambiguous mixed route: $slug/$format");
    }
}
