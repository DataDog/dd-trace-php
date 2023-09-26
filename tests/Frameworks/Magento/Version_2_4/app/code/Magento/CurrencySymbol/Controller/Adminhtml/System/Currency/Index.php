<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CurrencySymbol\Controller\Adminhtml\System\Currency;

use Magento\Framework\App\Action\HttpGetActionInterface as HttpGetActionInterface;

class Index extends \Magento\CurrencySymbol\Controller\Adminhtml\System\Currency implements HttpGetActionInterface
{
    /**
     * Currency management main page
     *
     * @return void
     */
    public function execute()
    {
        $this->_view->loadLayout();
        $this->_setActiveMenu('Magento_CurrencySymbol::system_currency_rates');
        $this->_view->getPage()->getConfig()->getTitle()->prepend(__('Currency Rates'));
        $this->_addContent(
            $this->_view->getLayout()->createBlock(\Magento\CurrencySymbol\Block\Adminhtml\System\Currency::class)
        );
        $this->_view->renderLayout();
    }
}
