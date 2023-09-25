<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Security\Test\Unit\Model\SecurityChecker;

use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Security\Model\ConfigInterface;
use Magento\Security\Model\ResourceModel\PasswordResetRequestEvent\Collection;
use Magento\Security\Model\ResourceModel\PasswordResetRequestEvent\CollectionFactory;

/**
 * Test class for \Magento\Security\Model\SecurityChecker\Quantity testing
 */
class QuantityTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var  \Magento\Security\Model\SecurityChecker\Quantity
     */
    protected $model;

    /**
     * @var ConfigInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $securityConfigMock;

    /**
     * @var CollectionFactory | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $collectionFactoryMock;

    /**
     * @var Collection | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $collectionMock;

    /**
     * @var  \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /*
     * @var RemoteAddress
     */
    protected $remoteAddressMock;

    /**
     * Init mocks for tests
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->securityConfigMock =  $this->getMockBuilder(\Magento\Security\Model\ConfigInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getScopeByEventType'])
            ->getMockForAbstractClass();
        $this->securityConfigMock->expects($this->any())
            ->method('getScopeByEventType')
            ->willReturnMap(
                [
                    [0, 1],
                    [1, 0]
                ]
            );

        $this->collectionFactoryMock = $this->createPartialMock(
            \Magento\Security\Model\ResourceModel\PasswordResetRequestEvent\CollectionFactory::class,
            ['create']
        );

        $this->collectionMock = $this->createPartialMock(
            \Magento\Security\Model\ResourceModel\PasswordResetRequestEvent\Collection::class,
            ['addFieldToFilter', 'filterByLifetime', 'count']
        );

        $this->remoteAddressMock =  $this->getMockBuilder(RemoteAddress::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = $this->objectManager->getObject(
            \Magento\Security\Model\SecurityChecker\Quantity::class,
            [
                'securityConfig' => $this->securityConfigMock,
                'collectionFactory' => $this->collectionFactoryMock,
                'remoteAddress' => $this->remoteAddressMock
            ]
        );
    }

    /**
     * @param int $securityEventType
     * @param int $requestsMethod
     * @dataProvider dataProviderSecurityEventTypeWithRequestsMethod
     */
    public function testCheck($securityEventType, $requestsMethod)
    {
        $limitNumberPasswordResetRequests = 10;

        $this->prepareTestCheck($requestsMethod, $limitNumberPasswordResetRequests);

        $this->collectionMock->expects($this->once())
            ->method('count')
            ->willReturn($limitNumberPasswordResetRequests - 1);

        $this->model->check($securityEventType);
    }

    /**
     * @param int $securityEventType
     * @param int $requestsMethod
     * @dataProvider dataProviderSecurityEventTypeWithRequestsMethod
     */
    public function testCheckException($securityEventType, $requestsMethod)
    {
        $this->expectException(\Magento\Framework\Exception\SecurityViolationException::class);

        $limitNumberPasswordResetRequests = 10;

        $this->prepareTestCheck($requestsMethod, $limitNumberPasswordResetRequests);

        $this->collectionMock->expects($this->once())
            ->method('count')
            ->willReturn($limitNumberPasswordResetRequests);

        $this->model->check($securityEventType);

        $this->expectExceptionMessage(
            'We received too many requests for password resets. '
            . 'Please wait and try again later or contact test@host.com.'
        );
    }

    /**
     * @return array
     */
    public function dataProviderSecurityEventTypeWithRequestsMethod()
    {
        return [
            [
                \Magento\Security\Model\PasswordResetRequestEvent::CUSTOMER_PASSWORD_RESET_REQUEST,
                \Magento\Security\Model\Config\Source\ResetMethod::OPTION_BY_IP_AND_EMAIL
            ],
            [
                \Magento\Security\Model\PasswordResetRequestEvent::CUSTOMER_PASSWORD_RESET_REQUEST,
                \Magento\Security\Model\Config\Source\ResetMethod::OPTION_BY_IP
            ],
            [
                \Magento\Security\Model\PasswordResetRequestEvent::CUSTOMER_PASSWORD_RESET_REQUEST,
                \Magento\Security\Model\Config\Source\ResetMethod::OPTION_BY_EMAIL
            ],
            [
                \Magento\Security\Model\PasswordResetRequestEvent::ADMIN_PASSWORD_RESET_REQUEST,
                \Magento\Security\Model\Config\Source\ResetMethod::OPTION_BY_IP_AND_EMAIL
            ],
            [
                \Magento\Security\Model\PasswordResetRequestEvent::ADMIN_PASSWORD_RESET_REQUEST,
                \Magento\Security\Model\Config\Source\ResetMethod::OPTION_BY_IP
            ],
            [
                \Magento\Security\Model\PasswordResetRequestEvent::ADMIN_PASSWORD_RESET_REQUEST,
                \Magento\Security\Model\Config\Source\ResetMethod::OPTION_BY_EMAIL
            ]
        ];
    }

    /**
     * @param int $requestsMethod
     * @param int $limitNumberPasswordResetRequests
     */
    protected function prepareTestCheck($requestsMethod, $limitNumberPasswordResetRequests)
    {
        $this->remoteAddressMock->expects($this->any())
            ->method('getRemoteAddress')
            ->willReturn(12345);

        $this->securityConfigMock->expects($this->any())
            ->method('getPasswordResetProtectionType')
            ->willReturn($requestsMethod);

        $this->securityConfigMock->expects($this->once())
            ->method('getMaxNumberPasswordResetRequests')
            ->willReturn($limitNumberPasswordResetRequests);

        $this->securityConfigMock->expects($this->any())
            ->method('getCustomerServiceEmail')
            ->willReturn('test@host.com');

        $this->collectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->collectionMock);

        $this->collectionMock->expects($this->any())
            ->method('addFieldToFilter')
            ->willReturnSelf();

        $this->collectionMock->expects($this->once())
            ->method('filterByLifetime')
            ->willReturnSelf();
    }
}
