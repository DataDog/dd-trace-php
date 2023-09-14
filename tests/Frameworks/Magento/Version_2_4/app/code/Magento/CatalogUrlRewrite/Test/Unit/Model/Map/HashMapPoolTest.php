<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CatalogUrlRewrite\Test\Unit\Model\Map;

use Magento\CatalogUrlRewrite\Model\Map\DataCategoryHashMap;
use Magento\CatalogUrlRewrite\Model\Map\DataCategoryUsedInProductsHashMap;
use Magento\CatalogUrlRewrite\Model\Map\DataProductHashMap;
use Magento\CatalogUrlRewrite\Model\Map\HashMapPool;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HashMapPoolTest extends TestCase
{
    /** @var ObjectManagerInterface|MockObject */
    private $objectManagerMock;

    /** @var HashMapPool|MockObject */
    private $model;

    protected function setUp(): void
    {
        $this->objectManagerMock = $this->getMockForAbstractClass(ObjectManagerInterface::class);

        $this->model = (new ObjectManager($this))->getObject(
            HashMapPool::class,
            [
                'objectManager' => $this->objectManagerMock,
            ]
        );
    }

    /**
     * Tests getDataMap
     */
    public function testGetDataMap()
    {
        $dataCategoryMapMock = $this->createMock(DataCategoryHashMap::class);
        $dataProductMapMock = $this->createMock(DataProductHashMap::class);
        $dataProductMapMockOtherCategory = $this->createMock(DataCategoryUsedInProductsHashMap::class);

        $this->objectManagerMock->expects($this->any())
            ->method('create')
            ->willReturnMap(
                [
                    [
                        DataCategoryHashMap::class,
                        ['category' => 1],
                        $dataCategoryMapMock
                    ],
                    [
                        DataProductHashMap::class,
                        ['category' => 1],
                        $dataProductMapMock
                    ],
                    [
                        DataCategoryUsedInProductsHashMap::class,
                        ['category' => 2],
                        $dataProductMapMockOtherCategory
                    ]
                ]
            );
        $this->assertSame($dataCategoryMapMock, $this->model->getDataMap(DataCategoryHashMap::class, 1));
        $this->assertSame($dataProductMapMock, $this->model->getDataMap(DataProductHashMap::class, 1));
        $this->assertSame(
            $dataProductMapMockOtherCategory,
            $this->model->getDataMap(DataCategoryUsedInProductsHashMap::class, 2)
        );
    }

    /**
     * Tests getDataMap with exception
     */
    public function testGetDataMapException()
    {
        $nonInterface = $this->createMock(HashMapPool::class);

        $this->objectManagerMock->expects($this->any())
            ->method('create')
            ->willReturn($nonInterface);
        $this->expectException(\InvalidArgumentException::class);
        $this->model->getDataMap(HashMapPool::class, 1);
    }
}
