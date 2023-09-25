<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Vault\Test\Unit\Block\Customer;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Block\Customer\AccountTokens;
use Magento\Vault\Model\AccountPaymentTokenFactory;
use Magento\Vault\Model\CreditCardTokenFactory;
use Magento\Vault\Model\CustomerTokenManagement;
use Magento\Vault\Model\PaymentToken;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AccountTokensTest extends TestCase
{
    /**
     * @var CustomerTokenManagement|MockObject
     */
    private $tokenManagement;

    /**
     * @var AccountTokens
     */
    private $block;

    /**
     * @var ObjectManager
     */
    private $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);

        $this->tokenManagement = $this->getMockBuilder(CustomerTokenManagement::class)
            ->disableOriginalConstructor()
            ->setMethods(['getCustomerSessionTokens'])
            ->getMock();

        $this->block = $this->objectManager->getObject(AccountTokens::class, [
            'customerTokenManagement' => $this->tokenManagement
        ]);
    }

    /**
     * @covers \Magento\Vault\Block\Customer\AccountTokens::getPaymentTokens
     */
    public function testGetPaymentTokens()
    {
        $cardToken = $this->objectManager->getObject(PaymentToken::class, [
            'data' => [PaymentTokenInterface::TYPE => CreditCardTokenFactory::TOKEN_TYPE_CREDIT_CARD]
        ]);
        $token = $this->objectManager->getObject(PaymentToken::class, [
            'data' => [PaymentTokenInterface::TYPE => AccountPaymentTokenFactory::TOKEN_TYPE_ACCOUNT]
        ]);
        $this->tokenManagement->expects(static::once())
            ->method('getCustomerSessionTokens')
            ->willReturn([$cardToken, $token]);

        $actual = $this->block->getPaymentTokens();
        static::assertCount(1, $actual);

        /** @var PaymentTokenInterface $actualToken */
        $actualToken = array_pop($actual);
        static::assertEquals(AccountPaymentTokenFactory::TOKEN_TYPE_ACCOUNT, $actualToken->getType());
    }
}
