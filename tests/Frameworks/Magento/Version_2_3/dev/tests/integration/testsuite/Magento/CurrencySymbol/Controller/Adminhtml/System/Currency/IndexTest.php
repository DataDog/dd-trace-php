<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CurrencySymbol\Controller\Adminhtml\System\Currency;

use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Helper\Bootstrap;

class IndexTest extends \Magento\TestFramework\TestCase\AbstractBackendController
{
    /**
     * Test index action
     */
    public function testIndexAction()
    {
        $objectManager = Bootstrap::getObjectManager();
        $configResource = $objectManager->get(\Magento\Config\Model\ResourceModel\Config::class);
        $configResource->saveConfig(
            'currency/options/base',
            'USD',
            ScopeInterface::SCOPE_STORE,
            0
        );
        $configResource->saveConfig(
            'currency/options/allow',
            'USD,GBP,EUR',
            ScopeInterface::SCOPE_STORE,
            0
        );
        $this->dispatch('backend/admin/system_currency/index');
        $this->getResponse()->isSuccess();
        $body = $this->getResponse()->getBody();
        $this->assertStringContainsString('id="rate-form"', $body);
        $this->assertStringContainsString('save primary save-currency-rates', $body);
        $this->assertStringContainsString('data-ui-id="page-actions-toolbar-reset-button"', $body);
    }
}
