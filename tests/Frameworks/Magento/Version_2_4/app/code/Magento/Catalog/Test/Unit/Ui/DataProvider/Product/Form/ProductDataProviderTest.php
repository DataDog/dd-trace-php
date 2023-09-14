<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Ui\DataProvider\Product\Form;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Ui\DataProvider\Product\Form\ProductDataProvider;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;
use Magento\Ui\DataProvider\Modifier\Pool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductDataProviderTest extends TestCase
{
    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var CollectionFactory|MockObject
     */
    protected $collectionFactoryMock;

    /**
     * @var Collection|MockObject
     */
    protected $collectionMock;

    /**
     * @var ModifierInterface|MockObject
     */
    protected $modifierMockOne;

    /**
     * @var Pool|MockObject
     */
    protected $poolMock;

    /**
     * @var ProductDataProvider
     */
    protected $model;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->collectionMock = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->collectionFactoryMock = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->collectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->collectionMock);
        $this->poolMock = $this->getMockBuilder(Pool::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->modifierMockOne = $this->getMockBuilder(ModifierInterface::class)
            ->setMethods(['getData', 'getMeta'])
            ->getMockForAbstractClass();

        $this->model = $this->objectManager->getObject(ProductDataProvider::class, [
            'name' => 'testName',
            'primaryFieldName' => 'testPrimaryFieldName',
            'requestFieldName' => 'testRequestFieldName',
            'collectionFactory' => $this->collectionFactoryMock,
            'pool' => $this->poolMock,
        ]);
    }

    public function testGetMeta()
    {
        $expectedMeta = ['meta_key' => 'meta_value'];

        $this->poolMock->expects($this->once())
            ->method('getModifiersInstances')
            ->willReturn([$this->modifierMockOne]);
        $this->modifierMockOne->expects($this->once())
            ->method('modifyMeta')
            ->willReturn($expectedMeta);

        $this->assertSame($expectedMeta, $this->model->getMeta());
    }

    public function testGetData()
    {
        $expectedMeta = ['data_key' => 'data_value'];

        $this->poolMock->expects($this->once())
            ->method('getModifiersInstances')
            ->willReturn([$this->modifierMockOne]);
        $this->modifierMockOne->expects($this->once())
            ->method('modifyData')
            ->willReturn($expectedMeta);

        $this->assertSame($expectedMeta, $this->model->getData());
    }
}
