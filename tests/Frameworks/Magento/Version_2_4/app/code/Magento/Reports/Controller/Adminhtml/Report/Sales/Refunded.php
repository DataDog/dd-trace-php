<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Reports\Controller\Adminhtml\Report\Sales;

use Magento\Framework\App\Action\HttpGetActionInterface as HttpGetActionInterface;
use Magento\Reports\Model\Flag;

class Refunded extends \Magento\Reports\Controller\Adminhtml\Report\Sales implements HttpGetActionInterface
{
    /**
     * Refunds report action
     *
     * @return void
     */
    public function execute()
    {
        $this->_showLastExecutionTime(Flag::REPORT_REFUNDED_FLAG_CODE, 'refunded');

        $this->_initAction()->_setActiveMenu(
            'Magento_Reports::report_salesroot_refunded'
        )->_addBreadcrumb(
            __('Total Refunded'),
            __('Total Refunded')
        );
        $this->_view->getPage()->getConfig()->getTitle()->prepend(__('Refunds Report'));

        $gridBlock = $this->_view->getLayout()->getBlock('adminhtml_sales_refunded.grid');
        $filterFormBlock = $this->_view->getLayout()->getBlock('grid.filter.form');

        $this->_initReportAction([$gridBlock, $filterFormBlock]);

        $this->_view->renderLayout();
    }
}
