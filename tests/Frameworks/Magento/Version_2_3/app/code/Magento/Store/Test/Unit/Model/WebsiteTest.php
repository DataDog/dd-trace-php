<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Store\Test\Unit\Model;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Website;
use Magento\Store\Model\WebsiteFactory;

class WebsiteTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Website
     */
    protected $model;

    /**
     * @var ObjectManager
     */
    protected $objectManagerHelper;

    /**
     * @var WebsiteFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $websiteFactory;

    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManager($this);

        $this->websiteFactory = $this->getMockBuilder(\Magento\Store\Model\WebsiteFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create', 'getCollection', '__wakeup'])
            ->getMock();

        /** @var Website $websiteModel */
        $this->model = $this->objectManagerHelper->getObject(
            \Magento\Store\Model\Website::class,
            ['websiteFactory' => $this->websiteFactory]
        );
    }

    public function testIsCanDelete()
    {
        $websiteCollection = $this->createPartialMock(
            \Magento\Store\Model\ResourceModel\Website\Collection::class,
            ['getSize']
        );
        $websiteCollection->expects($this->any())->method('getSize')->willReturn(2);

        $this->websiteFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->websiteFactory);
        $this->websiteFactory->expects($this->any())
            ->method('getCollection')
            ->willReturn($websiteCollection);

        $this->model->setId(2);
        $this->assertTrue($this->model->isCanDelete());
    }

    public function testGetScopeType()
    {
        $this->assertEquals(ScopeInterface::SCOPE_WEBSITE, $this->model->getScopeType());
    }

    public function testGetScopeTypeName()
    {
        $this->assertEquals('Website', $this->model->getScopeTypeName());
    }
}
