<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Test\Unit\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Signifyd\Model\SignifydOrderSessionId;
use PHPUnit\Framework\MockObject\MockObject as MockObject;

/**
 * Class SignifydOrderSessionIdTest tests that SignifydOrderSessionId class dependencies
 * follow the contracts.
 */
class SignifydOrderSessionIdTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var SignifydOrderSessionId
     */
    private $signifydOrderSessionId;

    /**
     * @var IdentityGeneratorInterface|MockObject
     */
    private $identityGenerator;

    /**
     * Sets up testing class and dependency mocks.
     */
    protected function setUp(): void
    {
        $this->identityGenerator = $this->getMockBuilder(IdentityGeneratorInterface::class)
            ->getMockForAbstractClass();

        $this->signifydOrderSessionId = new SignifydOrderSessionId($this->identityGenerator);
    }

    /**
     * Tests method by passing quoteId parameter
     *
     * @covers \Magento\Signifyd\Model\SignifydOrderSessionId::get
     */
    public function testGetByQuoteId()
    {
        $quoteId = 1;
        $signifydOrderSessionId = 'asdfzxcv';

        $this->identityGenerator->expects(self::once())
            ->method('generateIdForData')
            ->with($quoteId)
            ->willReturn($signifydOrderSessionId);

        $this->assertEquals(
            $signifydOrderSessionId,
            $this->signifydOrderSessionId->get($quoteId)
        );
    }
}
