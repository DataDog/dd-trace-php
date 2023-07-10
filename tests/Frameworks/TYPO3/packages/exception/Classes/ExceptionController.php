<?php

namespace Datadog\Exception;

use Exception;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class ExceptionController extends ActionController
{
    public function showAction(): mixed
    {
        throw new Exception('Not Implemented', 1666544465);
    }
}
