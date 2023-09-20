<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogUrlRewrite\Test\Unit\Model\Category\Plugin\Category;

use Magento\CatalogUrlRewrite\Model\Category\Plugin\Category\Remove as CategoryRemovePlugin;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\CatalogUrlRewrite\Model\Category\ChildrenCategoriesProvider;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResourceModel;
use Magento\Catalog\Model\Category;

class RemoveTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var UrlPersistInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $urlPersistMock;

    /**
     * @var ChildrenCategoriesProvider|\PHPUnit\Framework\MockObject\MockObject
     */
    private $childrenCategoriesProviderMock;

    /**
     * @var CategoryResourceModel|\PHPUnit\Framework\MockObject\MockObject
     */
    private $subjectMock;

    /**
     * @var Category|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectMock;

    /** @var \Magento\Framework\Serialize\Serializer\Json|\PHPUnit\Framework\MockObject\MockObject */
    private $serializerMock;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->urlPersistMock = $this->getMockBuilder(UrlPersistInterface::class)
            ->getMockForAbstractClass();
        $this->childrenCategoriesProviderMock = $this->getMockBuilder(ChildrenCategoriesProvider::class)
            ->getMock();
        $this->subjectMock = $this->getMockBuilder(CategoryResourceModel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->objectMock = $this->getMockBuilder(Category::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->serializerMock = $this->createMock(\Magento\Framework\Serialize\Serializer\Json::class);
    }

    public function testAroundDelete()
    {
        $closureSubject = $this->subjectMock;
        $proceed  = function () use ($closureSubject) {
            return $closureSubject;
        };
        $plugin = $this->objectManager->getObject(
            CategoryRemovePlugin::class,
            [
                'urlPersist' => $this->urlPersistMock,
                'childrenCategoriesProvider' => $this->childrenCategoriesProviderMock,
                'serializer' => $this->serializerMock
            ]
        );
        $this->childrenCategoriesProviderMock->expects($this->once())
            ->method('getChildrenIds')
            ->with($this->objectMock, true)
            ->willReturn([]);
        $this->objectMock->expects($this->once())
            ->method('getId')
            ->willReturn(1);
        $this->urlPersistMock->expects($this->exactly(2))
            ->method('deleteByData');
        $this->serializerMock->expects($this->once())
            ->method('serialize')
            ->with(['category_id' => 1]);
        $this->assertSame(
            $this->subjectMock,
            $plugin->aroundDelete($this->subjectMock, $proceed, $this->objectMock)
        );
    }
}
