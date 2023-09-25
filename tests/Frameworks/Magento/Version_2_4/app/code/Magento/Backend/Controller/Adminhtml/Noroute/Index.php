<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Controller\Adminhtml\Noroute;

/**
 * @SuppressWarnings(PHPMD.AllPurposeAction)
 */
class Index extends \Magento\Backend\App\Action
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory;

    /**
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Framework\View\Result\PageFactory $resultPageFactory
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    /**
     * Noroute action
     *
     * @return \Magento\Backend\Model\View\Result\Page
     */
    public function execute()
    {
        /** @var \Magento\Backend\Model\View\Result\Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setStatusHeader(404, '1.1', 'Not Found');
        $resultPage->setHeader('Status', '404 File not found');
        $resultPage->addHandle('adminhtml_noroute');
        return $resultPage;
    }

    /**
     * Error page should be public accessible. Do not check keys to avoid redirect loop
     *
     * @return bool
     */
    protected function _validateSecretKey()
    {
        return true;
    }
}
