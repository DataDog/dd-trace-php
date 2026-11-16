<?php

declare(strict_types=1);

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;

class DynamicPathController extends AbstractActionController
{
    public function indexAction()
    {
        $response = $this->getResponse();
        $response->setContent('ok');
        return $response;
    }
}
