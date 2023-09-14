<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Reports\Block\Adminhtml\Shopcart;

use Magento\TestFramework\Helper\Bootstrap;
use Magento\Quote\Model\Quote;

abstract class GridTestAbstract extends \PHPUnit\Framework\TestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $objectManager = Bootstrap::getObjectManager();

        /** @var \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository */
        $customerRepository = $objectManager->create(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $customerData = $customerRepository->getById(1);

        /** @var Quote $quoteFixture */
        $quoteFixture = $objectManager->create(\Magento\Quote\Model\Quote::class);
        $quoteFixture->load('test01', 'reserved_order_id');
        $quoteFixture->setIsActive(true);
        $quoteFixture->setCustomer($customerData);
        $quoteFixture->save();
    }
}
