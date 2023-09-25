<?php
/**
 * Redirect action class
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Action;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;

/**
 * Issue a redirect.
 *
 * @SuppressWarnings(PHPMD.AllPurposeAction)
 */
class Redirect extends AbstractAction
{
    /**
     * Redirect response
     *
     * @param RequestInterface $request
     * @return ResponseInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function dispatch(RequestInterface $request)
    {
        return $this->execute();
    }

    /**
     * @return ResponseInterface
     */
    public function execute()
    {
        return $this->_response;
    }
}
