<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CmsUrlRewrite\Test\Unit\Plugin\Cms\Model\ResourceModel;

use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\CmsUrlRewrite\Model\CmsPageUrlRewriteGenerator;

class PageTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\CmsUrlRewrite\Plugin\Cms\Model\ResourceModel\Page
     */
    protected $pageObject;

    /**
     * @var \Magento\UrlRewrite\Model\UrlPersistInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $urlPersistMock;

    /**
     * @var \Magento\Cms\Model\Page|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $cmsPageMock;

    /**
     * @var \Magento\Cms\Model\ResourceModel\Page|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $cmsPageResourceMock;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->urlPersistMock = $this->getMockBuilder(\Magento\UrlRewrite\Model\UrlPersistInterface::class)
            ->getMockForAbstractClass();

        $this->cmsPageMock = $this->getMockBuilder(\Magento\Cms\Model\Page::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->cmsPageResourceMock = $this->getMockBuilder(\Magento\Cms\Model\ResourceModel\Page::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->pageObject = $objectManager->getObject(
            \Magento\CmsUrlRewrite\Plugin\Cms\Model\ResourceModel\Page::class,
            [
                'urlPersist' => $this->urlPersistMock
            ]
        );
    }

    public function testAfterDeletePositive()
    {
        $productId = 100;

        $this->cmsPageMock->expects($this->once())
            ->method('getId')
            ->willReturn($productId);

        $this->cmsPageMock->expects($this->once())
            ->method('isDeleted')
            ->willReturn(true);

        $this->urlPersistMock->expects($this->once())
            ->method('deleteByData')
            ->with(
                [
                    UrlRewrite::ENTITY_ID => $productId,
                    UrlRewrite::ENTITY_TYPE => CmsPageUrlRewriteGenerator::ENTITY_TYPE
                ]
            );

        $this->assertSame(
            $this->cmsPageResourceMock,
            $this->pageObject->afterDelete(
                $this->cmsPageResourceMock,
                $this->cmsPageResourceMock,
                $this->cmsPageMock
            )
        );
    }

    public function testAfterDeleteNegative()
    {
        $this->cmsPageMock->expects($this->once())
            ->method('isDeleted')
            ->willReturn(false);

        $this->urlPersistMock->expects($this->never())
            ->method('deleteByData');

        $this->assertSame(
            $this->cmsPageResourceMock,
            $this->pageObject->afterDelete(
                $this->cmsPageResourceMock,
                $this->cmsPageResourceMock,
                $this->cmsPageMock
            )
        );
    }
}
