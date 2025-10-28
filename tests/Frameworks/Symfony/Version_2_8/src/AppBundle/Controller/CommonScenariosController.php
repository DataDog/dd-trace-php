<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

class CommonScenariosController extends Controller
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
            'base_dir' => realpath($this->getParameter('kernel.root_dir')).DIRECTORY_SEPARATOR,
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

    /**
     * @Route("/behind_auth", name="behind_auth")
     */
    public function behindAuthAction(Request $request)
    {
        // replace this example code with whatever you need
        return new Response('Hi!');
    }

    /**
     * @Route("/telemetry", name="telemetry")
     */
    public function telemetryAction(Request $request)
    {
        dd_trace_internal_fn("finalize_telemetry");
        return new Response('Done');
    }    
}
