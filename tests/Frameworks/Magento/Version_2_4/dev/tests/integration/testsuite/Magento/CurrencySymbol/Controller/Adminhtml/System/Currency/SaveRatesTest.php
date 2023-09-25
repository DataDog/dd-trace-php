<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CurrencySymbol\Controller\Adminhtml\System\Currency;

use Magento\Directory\Model\Currency;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Escaper;

class SaveRatesTest extends \Magento\TestFramework\TestCase\AbstractBackendController
{
    /** @var Currency $currencyRate */
    protected $currencyRate;

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->currencyRate = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            Currency::class
        );
        $this->escaper = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            Escaper::class
        );

        parent::setUp();
    }

    /**
     * Test save action
     *
     * @magentoDbIsolation enabled
     */
    public function testSaveAction(): void
    {
        $currencyCode = 'USD';
        $currencyTo = 'USD';
        $rate = 1.0000;

        $request = $this->getRequest();
        $request->setMethod(HttpRequest::METHOD_POST);
        $request->setPostValue(
            'rate',
            [
                $currencyCode => [$currencyTo => $rate]
            ]
        );
        $this->dispatch('backend/admin/system_currency/saveRates');

        $this->assertSessionMessages(
            $this->containsEqual((string)__('All valid rates have been saved.')),
            \Magento\Framework\Message\MessageInterface::TYPE_SUCCESS
        );

        $this->assertEquals(
            $rate,
            $this->currencyRate->load($currencyCode)->getRate($currencyTo),
            'Currency rate has not been saved'
        );
    }

    /**
     * Test save action with warning
     *
     * @magentoDbIsolation enabled
     */
    public function testSaveWithWarningAction(): void
    {
        $currencyCode = 'USD';
        $currencyTo = 'USD';
        $rate = '0';

        $request = $this->getRequest();
        $request->setMethod(HttpRequest::METHOD_POST);
        $request->setPostValue(
            'rate',
            [
                $currencyCode => [$currencyTo => $rate]
            ]
        );
        $this->dispatch('backend/admin/system_currency/saveRates');

        $this->assertSessionMessages(
            $this->containsEqual(
                $this->escaper->escapeHtml(
                    (string)__('Please correct the input data for "%1 => %2" rate.', $currencyCode, $currencyTo)
                )
            ),
            \Magento\Framework\Message\MessageInterface::TYPE_WARNING
        );
    }

    /**
     * Test save action with warning
     *
     * @magentoDbIsolation enabled
     */
    public function testSaveWithNullRates(): void
    {
        $currencyCode = 'USD';
        $currencyTo = 'USD';
        $rate = null;

        $request = $this->getRequest();
        $request->setMethod(HttpRequest::METHOD_POST);
        $request->setPostValue(
            'rate',
            [
                $currencyCode => [$currencyTo => $rate]
            ]
        );
        $this->dispatch('backend/admin/system_currency/saveRates');

        $this->assertSessionMessages(
            $this->containsEqual(
                $this->escaper->escapeHtml(
                    (string)__('Please correct the input data for "%1 => %2" rate.', $currencyCode, $currencyTo)
                )
            ),
            \Magento\Framework\Message\MessageInterface::TYPE_WARNING
        );
    }
}
