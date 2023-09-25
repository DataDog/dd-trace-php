<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogUrlRewrite\Test\Unit\Model\Category\Plugin\Category;

use Magento\Catalog\Model\CategoryFactory;
use Magento\CatalogUrlRewrite\Model\Category\Plugin\Category\Move as CategoryMovePlugin;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\CatalogUrlRewrite\Model\Category\ChildrenCategoriesProvider;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResourceModel;
use Magento\Catalog\Model\Category;

class MoveTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var ChildrenCategoriesProvider|\PHPUnit\Framework\MockObject\MockObject
     */
    private $childrenCategoriesProviderMock;

    /**
     * @var CategoryUrlPathGenerator|\PHPUnit\Framework\MockObject\MockObject
     */
    private $categoryUrlPathGeneratorMock;

    /**
     * @var CategoryResourceModel|\PHPUnit\Framework\MockObject\MockObject
     */
    private $subjectMock;

    /**
     * @var Category|\PHPUnit\Framework\MockObject\MockObject
     */
    private $categoryMock;

    /**
     * @var CategoryFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $categoryFactory;

    /**
     * @var CategoryMovePlugin
     */
    private $plugin;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->categoryUrlPathGeneratorMock = $this->getMockBuilder(CategoryUrlPathGenerator::class)
            ->disableOriginalConstructor()
            ->setMethods(['getUrlPath'])
            ->getMock();
        $this->childrenCategoriesProviderMock = $this->getMockBuilder(ChildrenCategoriesProvider::class)
            ->disableOriginalConstructor()
            ->setMethods(['getChildren'])
            ->getMock();
        $this->categoryFactory = $this->getMockBuilder(CategoryFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->subjectMock = $this->getMockBuilder(CategoryResourceModel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->categoryMock = $this->getMockBuilder(Category::class)
            ->disableOriginalConstructor()
            ->setMethods(['getResource', 'setUrlPath', 'getStoreIds', 'getStoreId', 'setStoreId'])
            ->getMock();
        $this->plugin = $this->objectManager->getObject(
            CategoryMovePlugin::class,
            [
                'categoryUrlPathGenerator' => $this->categoryUrlPathGeneratorMock,
                'childrenCategoriesProvider' => $this->childrenCategoriesProviderMock,
                'categoryFactory' => $this->categoryFactory
            ]
        );
    }

    /**
     * Tests url updating for children categories.
     */
    public function testAfterChangeParent()
    {
        $urlPath = 'test/path';
        $storeIds = [1];
        $originalCategory = $this->getMockBuilder(Category::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->categoryFactory->method('create')
            ->willReturn($originalCategory);

        $this->categoryMock->method('getResource')
            ->willReturn($this->subjectMock);
        $this->categoryMock->expects($this->once())
            ->method('getStoreIds')
            ->willReturn($storeIds);
        $this->childrenCategoriesProviderMock->expects($this->once())
            ->method('getChildren')
            ->with($this->categoryMock, true)
            ->willReturn([]);
        $this->categoryUrlPathGeneratorMock->expects($this->once())
            ->method('getUrlPath')
            ->with($this->categoryMock)
            ->willReturn($urlPath);
        $this->categoryMock->expects($this->once())
            ->method('setUrlPath')
            ->with($urlPath);
        $this->assertSame(
            $this->subjectMock,
            $this->plugin->afterChangeParent(
                $this->subjectMock,
                $this->subjectMock,
                $this->categoryMock,
                $this->categoryMock,
                null
            )
        );
    }
}
