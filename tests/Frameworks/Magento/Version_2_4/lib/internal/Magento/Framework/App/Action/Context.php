<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Action;

use Magento\Framework\Controller\ResultFactory;

/**
 * Constructor modification point for Magento\Framework\App\Action.
 *
 * All context classes were introduced to allow for backwards compatible constructor modifications
 * of classes that were supposed to be extended by extension developers.
 *
 * Do not call methods of this class directly.
 *
 * As Magento moves from inheritance-based APIs all such classes will be deprecated together with
 * the classes they were introduced for.
 *
 * @api
 * @since 100.0.2
 */
class Context implements \Magento\Framework\ObjectManager\ContextInterface
{
    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $_request;

    /**
     * @var \Magento\Framework\App\ResponseInterface
     */
    protected $_response;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $_eventManager;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $_url;

    /**
     * @var \Magento\Framework\App\Response\RedirectInterface
     */
    protected $_redirect;

    /**
     * @var \Magento\Framework\App\ActionFlag
     */
    protected $_actionFlag;

    /**
     * @var \Magento\Framework\App\ViewInterface
     */
    protected $_view;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \Magento\Framework\Controller\Result\RedirectFactory
     */
    protected $resultRedirectFactory;

    /**
     * @var \Magento\Framework\Controller\ResultFactory
     */
    protected $resultFactory;

    /**
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Framework\App\ResponseInterface $response
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Framework\UrlInterface $url
     * @param \Magento\Framework\App\Response\RedirectInterface $redirect
     * @param \Magento\Framework\App\ActionFlag $actionFlag
     * @param \Magento\Framework\App\ViewInterface $view
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     * @param \Magento\Framework\Controller\Result\RedirectFactory $resultRedirectFactory
     * @param \Magento\Framework\Controller\ResultFactory $resultFactory
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\App\ResponseInterface $response,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\UrlInterface $url,
        \Magento\Framework\App\Response\RedirectInterface $redirect,
        \Magento\Framework\App\ActionFlag $actionFlag,
        \Magento\Framework\App\ViewInterface $view,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Framework\Controller\Result\RedirectFactory $resultRedirectFactory,
        ResultFactory $resultFactory
    ) {
        $this->_request = $request;
        $this->_response = $response;
        $this->_objectManager = $objectManager;
        $this->_eventManager = $eventManager;
        $this->_url = $url;
        $this->_redirect = $redirect;
        $this->_actionFlag = $actionFlag;
        $this->_view = $view;
        $this->messageManager = $messageManager;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->resultFactory = $resultFactory;
    }

    /**
     * @return \Magento\Framework\App\ActionFlag
     */
    public function getActionFlag()
    {
        return $this->_actionFlag;
    }

    /**
     * @return \Magento\Framework\Event\ManagerInterface
     */
    public function getEventManager()
    {
        return $this->_eventManager;
    }

    /**
     * @return \Magento\Framework\App\ViewInterface
     */
    public function getView()
    {
        return $this->_view;
    }

    /**
     * @return \Magento\Framework\ObjectManagerInterface
     */
    public function getObjectManager()
    {
        return $this->_objectManager;
    }

    /**
     * @return \Magento\Framework\App\Response\RedirectInterface
     */
    public function getRedirect()
    {
        return $this->_redirect;
    }

    /**
     * @return \Magento\Framework\App\RequestInterface
     */
    public function getRequest()
    {
        return $this->_request;
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface
     */
    public function getResponse()
    {
        return $this->_response;
    }

    /**
     * @return \Magento\Framework\UrlInterface
     */
    public function getUrl()
    {
        return $this->_url;
    }

    /**
     * @return \Magento\Framework\Message\ManagerInterface
     */
    public function getMessageManager()
    {
        return $this->messageManager;
    }

    /**
     * @return \Magento\Framework\Controller\Result\RedirectFactory
     */
    public function getResultRedirectFactory()
    {
        return $this->resultRedirectFactory;
    }

    /**
     * @return \Magento\Framework\Controller\ResultFactory
     */
    public function getResultFactory()
    {
        return $this->resultFactory;
    }
}
