<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\Product;

class TypeTransitionManagerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Model\Product\TypeTransitionManager
     */
    protected $model;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $productMock;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $weightResolver;

    protected function setUp()
    {
        $this->productMock = $this->createPartialMock(
            \Magento\Catalog\Model\Product::class,
            ['getTypeId', 'setTypeId', 'setTypeInstance']
        );
        $this->weightResolver = $this->createMock(\Magento\Catalog\Model\Product\Edit\WeightResolver::class);
        $this->model = (new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this))
            ->getObject(
                \Magento\Catalog\Model\Product\TypeTransitionManager::class,
                [
                    'weightResolver' => $this->weightResolver,
                    'compatibleTypes' => [
                        'simple' => \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE,
                        'virtual' => \Magento\Catalog\Model\Product\Type::TYPE_VIRTUAL,
                    ]
                ]
            );
    }

    /**
     * @param bool $hasWeight
     * @param string $currentTypeId
     * @param string $expectedTypeId
     * @dataProvider processProductDataProvider
     */
    public function testProcessProduct($hasWeight, $currentTypeId, $expectedTypeId)
    {
        $this->productMock->expects($this->once())->method('getTypeId')->will($this->returnValue($currentTypeId));
        $this->productMock->expects($this->once())->method('setTypeInstance')->with(null);
        $this->weightResolver->expects($this->once())->method('resolveProductHasWeight')->willReturn($hasWeight);
        $this->productMock->expects($this->once())->method('setTypeId')->with($expectedTypeId);
        $this->model->processProduct($this->productMock);
    }

    /**
     * @return void
     */
    public function testProcessProductWithWrongTypeId()
    {
        $this->productMock->expects($this->once())->method('getTypeId')->will($this->returnValue('wrong-type'));
        $this->weightResolver->expects($this->never())->method('resolveProductHasWeight');
        $this->model->processProduct($this->productMock);
    }

    /**
     * @return array
     */
    public function processProductDataProvider()
    {
        return [
            [
                true,
                \Magento\Catalog\Model\Product\Type::TYPE_VIRTUAL,
                \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE,
            ],
            [
                true,
                \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE,
                \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE,
            ],
            [
                false,
                \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE,
                \Magento\Catalog\Model\Product\Type::TYPE_VIRTUAL,
            ],
            [
                false,
                \Magento\Catalog\Model\Product\Type::TYPE_VIRTUAL,
                \Magento\Catalog\Model\Product\Type::TYPE_VIRTUAL,
            ]
        ];
    }
}
