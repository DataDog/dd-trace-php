<?php

namespace CustomElement\Datadog\Controller\Error;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;

class Index extends Action implements HttpGetActionInterface
{
    /**
     * @inheritDoc
     */
    public function execute()
    {
        throw new \Exception('This is an exception');
    }
}
