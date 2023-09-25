<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Paypal\Model\Payment\Method\Billing;

use Magento\Quote\Api\Data\PaymentInterface;
use Magento\TestFramework\Helper\Bootstrap;

class AbstractAgreementTest extends \Magento\TestFramework\Indexer\TestCase
{
    /** @var \Magento\Paypal\Model\Method\Agreement */
    protected $_model;

    public static function setUpBeforeClass(): void
    {
        $db = Bootstrap::getInstance()->getBootstrap()
            ->getApplication()
            ->getDbInstance();
        if (!$db->isDbDumpExists()) {
            throw new \LogicException('DB dump does not exist.');
        }
        $db->restoreFromDbDump();

        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        $config = $this->getMockBuilder(\Magento\Paypal\Model\Config::class)->disableOriginalConstructor()->getMock();
        $config->expects($this->any())->method('isMethodAvailable')->willReturn(true);
        $proMock = $this->getMockBuilder(\Magento\Paypal\Model\Pro::class)->disableOriginalConstructor()->getMock();
        $proMock->expects($this->any())->method('getConfig')->willReturn($config);
        $this->_model = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Paypal\Model\Method\Agreement::class,
            ['data' => [$proMock]]
        );
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/quote_with_customer.php
     * @magentoDataFixture Magento/Paypal/_files/billing_agreement.php
     */
    public function testIsActive()
    {
        $quote = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Quote\Model\ResourceModel\Quote\Collection::class
        )->getFirstItem();
        $this->assertTrue($this->_model->isAvailable($quote));
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/quote_with_customer.php
     * @magentoDataFixture Magento/Paypal/_files/billing_agreement.php
     */
    public function testAssignData()
    {
        /** @var \Magento\Quote\Model\ResourceModel\Quote\Collection $collection */
        $collection = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Quote\Model\ResourceModel\Quote\Collection::class
        );
        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $collection->getFirstItem();

        /** @var \Magento\Payment\Model\Info $info */
        $info = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Payment\Model\Info::class
        )->setQuote(
            $quote
        );
        $this->_model->setData('info_instance', $info);
        $billingAgreement = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Paypal\Model\ResourceModel\Billing\Agreement\Collection::class
        )->getFirstItem();
        $data = new \Magento\Framework\DataObject(
            [
                PaymentInterface::KEY_ADDITIONAL_DATA => [
                    AbstractAgreement::TRANSPORT_BILLING_AGREEMENT_ID => $billingAgreement->getId()
                ]
            ]
        );
        $this->_model->assignData($data);
        $this->assertEquals(
            'REF-ID-TEST-678',
            $info->getAdditionalInformation(AbstractAgreement::PAYMENT_INFO_REFERENCE_ID)
        );
    }

    /**
     * teardown
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
