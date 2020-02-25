<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CommonScenariosController extends AbstractController
{
    /**
     * @Route("/simple", name="simple")
     */
    public function simpleAction(Request $request)
    {
        // replace this example code with whatever you need
        return new Response(
            'Hi!'
        );
    }

    /**
     * @Route("/simple_view", name="simple_view")
     */
    public function simpleViewAction(Request $request)
    {
        // replace this example code with whatever you need
        return $this->render('twig_template.html.twig', [
            'base_dir' => realpath($this->getParameter('kernel.project_dir')).DIRECTORY_SEPARATOR,
        ]);
    }

    /**
     * @Route("/error", name="error")
     * @throws \Exception
     */
    public function errorAction(Request $request)
    {
        throw new \Exception('An exception occurred');
    }
}
