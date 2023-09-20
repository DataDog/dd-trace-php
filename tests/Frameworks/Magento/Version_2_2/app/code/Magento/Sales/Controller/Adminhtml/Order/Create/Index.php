<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Controller\Adminhtml\Order\Create;

class Index extends \Magento\Sales\Controller\Adminhtml\Order\Create
{
    /**
     * Index page
     *
     * @return void
     */
    public function execute()
    {
        $this->_initSession();
        $this->_getOrderCreateModel()->initRuleData();
        /** @var \Magento\Backend\Model\View\Result\Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Magento_Sales::sales_order');
        $resultPage->getConfig()->getTitle()->prepend(__('Orders'));
        $resultPage->getConfig()->getTitle()->prepend(__('New Order'));
        return $resultPage;
    }
}
