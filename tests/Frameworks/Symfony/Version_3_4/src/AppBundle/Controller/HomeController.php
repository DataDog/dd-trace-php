<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Templating\Loader\FilesystemLoader;
use Symfony\Component\Templating\PhpEngine;
use Symfony\Component\Templating\TemplateNameParser;

class HomeController extends Controller
{
    /**
     * @Route("/alternate_templating", name="alternate_templating")
     */
    public function indexAction(Request $request)
    {
        $templateDir = implode(DIRECTORY_SEPARATOR, [
            realpath($this->getParameter('kernel.project_dir')),
            'app',
            'Resources',
            'views',
        ]);

        $filesystemLoader = new FilesystemLoader($templateDir . '/%name%');

        $templating = new PhpEngine(new TemplateNameParser(), $filesystemLoader);

        // replace this example code with whatever you need
        return new Response($templating->render('php_template.template.php', []));
    }

    /**
     * @Route("/terminated_by_exit", name="terminated_by_exit")
     */
    public function actionBeingTerminatedByExit(Request $request)
    {
        echo "This is calculated by some service";
        exit();
    }
}
