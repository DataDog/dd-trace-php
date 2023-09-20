<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Bundle\Test\Unit\Ui\DataProvider\Product\Form\Modifier;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Bundle\Model\Product\Type;
use PHPUnit\Framework\MockObject\MockObject as MockObject;

class CompositeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManagerHelper
     */
    protected $objectManagerHelper;

    /**
     * @var \Magento\Bundle\Ui\DataProvider\Product\Form\Modifier\Composite
     */
    protected $composite;

    /**
     * @var \Magento\Framework\ObjectManagerInterface|MockObject
     */
    protected $objectManagerMock;

    /**
     * @var \Magento\Catalog\Model\Locator\LocatorInterface|MockObject
     */
    protected $locatorMock;

    /**
     * @var \Magento\Catalog\Api\Data\ProductInterface|MockObject
     */
    protected $productMock;

    /**
     * @var array
     */
    protected $meta = [];

    /**
     * @var array
     */
    protected $modifiedMeta = [];

    /**
     * @var string
     */
    protected $modifierClass;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->meta = ['some_meta'];
        $this->modifiedMeta = ['modified_meta'];
        $this->modifierClass = 'SomeClass';
        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $this->locatorMock = $this->createMock(\Magento\Catalog\Model\Locator\LocatorInterface::class);
        $this->productMock = $this->createMock(\Magento\Catalog\Api\Data\ProductInterface::class);

        $this->locatorMock->expects($this->once())
            ->method('getProduct')
            ->willReturn($this->productMock);

        $this->composite = $this->objectManagerHelper->getObject(
            \Magento\Bundle\Ui\DataProvider\Product\Form\Modifier\Composite::class,
            [
                'locator' => $this->locatorMock,
                'objectManager' => $this->objectManagerMock,
                'modifiers' => ['mod' => $this->modifierClass]
            ]
        );
    }

    /**
     * @return void
     */
    public function testModifyMetaWithoutModifiers()
    {
        $this->composite = $this->objectManagerHelper->getObject(
            \Magento\Bundle\Ui\DataProvider\Product\Form\Modifier\Composite::class,
            [
                'locator' => $this->locatorMock,
                'objectManager' => $this->objectManagerMock,
                'modifiers' => []
            ]
        );
        $this->productMock->expects($this->once())
            ->method('getTypeId')
            ->willReturn(Type::TYPE_CODE);
        $this->objectManagerMock->expects($this->never())
            ->method('get');

        $this->assertSame($this->meta, $this->composite->modifyMeta($this->meta));
    }

    /**
     * @return void
     */
    public function testModifyMetaBundleProduct()
    {
        /** @var \Magento\Ui\DataProvider\Modifier\ModifierInterface|MockObject $modifierMock */
        $modifierMock = $this->createMock(\Magento\Ui\DataProvider\Modifier\ModifierInterface::class);
        $modifierMock->expects($this->once())
            ->method('modifyMeta')
            ->with($this->meta)
            ->willReturn($this->modifiedMeta);

        $this->productMock->expects($this->once())
            ->method('getTypeId')
            ->willReturn(Type::TYPE_CODE);
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with($this->modifierClass)
            ->willReturn($modifierMock);

        $this->assertSame($this->modifiedMeta, $this->composite->modifyMeta($this->meta));
    }

    /**
     * @return void
     */
    public function testModifyMetaNonBundleProduct()
    {
        /** @var \Magento\Ui\DataProvider\Modifier\ModifierInterface|MockObject $modifierMock */
        $modifierMock = $this->createMock(\Magento\Ui\DataProvider\Modifier\ModifierInterface::class);
        $modifierMock->expects($this->never())
            ->method('modifyMeta');

        $this->productMock->expects($this->once())
            ->method('getTypeId')
            ->willReturn('SomeTypeProduct');
        $this->objectManagerMock->expects($this->never())
            ->method('get');

        $this->assertSame($this->meta, $this->composite->modifyMeta($this->meta));
    }

    /**
     * @return void
     */
    public function testModifyMetaWithException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type "SomeClass" is not an instance of Magento\\Ui\\DataProvider\\Modifier\\ModifierInterface');

        /** @var \Exception|MockObject $modifierMock */
        $modifierMock = $this->createPartialMock(\Exception::class, ['modifyMeta']);
        $modifierMock->expects($this->never())
            ->method('modifyMeta');

        $this->productMock->expects($this->once())
            ->method('getTypeId')
            ->willReturn(Type::TYPE_CODE);
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with($this->modifierClass)
            ->willReturn($modifierMock);

        $this->composite->modifyMeta($this->meta);
    }
}
