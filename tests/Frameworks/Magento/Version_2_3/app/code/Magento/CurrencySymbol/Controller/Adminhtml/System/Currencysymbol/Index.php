<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CurrencySymbol\Controller\Adminhtml\System\Currencysymbol;

use Magento\Framework\App\Action\HttpGetActionInterface as HttpGetActionInterface;

class Index extends \Magento\CurrencySymbol\Controller\Adminhtml\System\Currencysymbol implements HttpGetActionInterface
{
    /**
     * Show Currency Symbols Management dialog
     *
     * @return void
     */
    public function execute()
    {
        // set active menu and breadcrumbs
        $this->_view->loadLayout();
        $this->_setActiveMenu(
            'Magento_CurrencySymbol::system_currency_symbols'
        )->_addBreadcrumb(
            __('System'),
            __('System')
        )->_addBreadcrumb(
            __('Manage Currency Rates'),
            __('Manage Currency Rates')
        );

        $this->_view->getPage()->getConfig()->getTitle()->prepend(__('Currency Symbols'));
        $this->_view->renderLayout();
    }
}
