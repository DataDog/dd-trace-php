<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Controller\Adminhtml\Order\Create;

use Magento\Framework\App\Action\HttpGetActionInterface as HttpGetActionInterface;

/**
 * Order create index page controller.
 */
class Index extends \Magento\Sales\Controller\Adminhtml\Order\Create implements HttpGetActionInterface
{
    /**
     * Index page
     *
     * @return \Magento\Backend\Model\View\Result\Page
     */
    public function execute()
    {
        $this->_initSession();

        // Clear existing order in session when creating a new order for a customer
        if ($this->getRequest()->getParam('customer_id')) {
            $this->_getSession()->setOrderId(null);
        }

        $this->_getOrderCreateModel()->initRuleData();
        /** @var \Magento\Backend\Model\View\Result\Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Magento_Sales::sales_order');
        $resultPage->getConfig()->getTitle()->prepend(__('Orders'));
        $resultPage->getConfig()->getTitle()->prepend(__('New Order'));
        return $resultPage;
    }
}
