<?php

declare(strict_types=1);

namespace Application\Controller;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class CommonSpecsController extends AbstractActionController
{
    public function simpleAction()
    {
        $response = new Response();
        $response->setContent('simple');
        return $response;
    }

    public function viewAction()
    {
        $viewModel = new ViewModel();
        $viewModel->setTemplate('application/common-specs/view');
        $viewModel->setTerminal(true);  // Disable layout
        return $viewModel;
    }

    /**
     * @throws \Exception
     */
    public function errorAction()
    {
        throw new \Exception('Controller error');
    }
}
