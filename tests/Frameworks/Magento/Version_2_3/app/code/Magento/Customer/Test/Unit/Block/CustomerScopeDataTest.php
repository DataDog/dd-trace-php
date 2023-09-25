<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Test\Unit\Block;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Block\CustomerScopeData;

class CustomerScopeDataTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Customer\Block\CustomerScopeData */
    private $model;

    /** @var \Magento\Framework\View\Element\Template\Context|\PHPUnit\Framework\MockObject\MockObject */
    private $contextMock;

    /** @var StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $storeManagerMock;

    /** @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfigMock;

    /** @var \Magento\Framework\Json\EncoderInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $encoderMock;

    /** @var \Magento\Framework\Serialize\Serializer\Json|\PHPUnit\Framework\MockObject\MockObject */
    private $serializerMock;

    protected function setUp(): void
    {
        $this->contextMock = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->storeManagerMock = $this->getMockBuilder(StoreManagerInterface::class)
            ->getMock();

        $this->scopeConfigMock = $this->getMockBuilder(ScopeConfigInterface::class)
            ->getMock();

        $this->encoderMock = $this->getMockBuilder(\Magento\Framework\Json\EncoderInterface::class)
            ->getMock();

        $this->serializerMock = $this->getMockBuilder(\Magento\Framework\Serialize\Serializer\Json::class)
            ->getMock();

        $this->contextMock->expects($this->exactly(2))
            ->method('getStoreManager')
            ->willReturn($this->storeManagerMock);

        $this->contextMock->expects($this->once())
            ->method('getScopeConfig')
            ->willReturn($this->scopeConfigMock);

        $this->model = new CustomerScopeData(
            $this->contextMock,
            $this->encoderMock,
            [],
            $this->serializerMock
        );
    }

    public function testGetWebsiteId()
    {
        $storeId = 1;

        $storeMock = $this->getMockBuilder(StoreInterface::class)
            ->setMethods(['getWebsiteId'])
            ->getMockForAbstractClass();

        $storeMock->expects($this->any())
            ->method('getWebsiteId')
            ->willReturn($storeId);

        $this->storeManagerMock->expects($this->any())
            ->method('getStore')
            ->with(null)
            ->willReturn($storeMock);

        $this->assertEquals($storeId, $this->model->getWebsiteId());
    }

    public function testEncodeConfiguration()
    {
        $rules = [
            '*' => [
                'Magento_Customer/js/invalidation-processor' => [
                    'invalidationRules' => [
                        'website-rule' => [
                            'Magento_Customer/js/invalidation-rules/website-rule' => [
                                'scopeConfig' => [
                                    'websiteId' => 1,
                                ]
                            ]
                        ]
                    ]
                ]
            ],
        ];

        $this->serializerMock->expects($this->any())
            ->method('serialize')
            ->with($rules)
            ->willReturn(json_encode($rules));

        $this->assertEquals(
            json_encode($rules),
            $this->model->encodeConfiguration($rules)
        );
    }
}
