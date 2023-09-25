<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\GroupedProduct\Test\Unit\Model\Product\Initialization\Helper\ProductLinks\Plugin;

use Magento\Catalog\Model\Product\Type;
use Magento\GroupedProduct\Model\Product\Type\Grouped;

/**
 * Class GroupedTest
 */
class GroupedTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $productLinkExtensionFactory;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $productLinkFactory;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $productRepository;

    /**
     * @var \Magento\GroupedProduct\Model\Product\Initialization\Helper\ProductLinks\Plugin\Grouped
     */
    protected $model;

    /**
     * @var \Magento\Catalog\Model\Product|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $productMock;

    /**
     * @var \Magento\Catalog\Model\Product\Initialization\Helper\ProductLinks|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $subjectMock;

    protected function setUp(): void
    {
        $this->productMock = $this->createPartialMock(
            \Magento\Catalog\Model\Product::class,
            [
                'getGroupedReadonly',
                '__wakeup',
                'getTypeId',
                'getSku',
                'getProductLinks',
                'setProductLinks',
                'setGroupedLinkData'
            ]
        );
        $this->subjectMock = $this->createMock(
            \Magento\Catalog\Model\Product\Initialization\Helper\ProductLinks::class
        );
        $this->productLinkExtensionFactory = $this->getMockBuilder(
            \Magento\Catalog\Api\Data\ProductLinkExtensionFactory::class
        )
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMockForAbstractClass();
        $this->productLinkFactory = $this->getMockBuilder(\Magento\Catalog\Api\Data\ProductLinkInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMockForAbstractClass();
        $this->productRepository = $this->getMockBuilder(\Magento\Catalog\Api\ProductRepositoryInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->model = new \Magento\GroupedProduct\Model\Product\Initialization\Helper\ProductLinks\Plugin\Grouped(
            $this->productLinkFactory,
            $this->productRepository,
            $this->productLinkExtensionFactory
        );
    }

    /**
     * @dataProvider productTypeDataProvider
     */
    public function testBeforeInitializeLinksRequestDoesNotHaveGrouped($productType)
    {
        $this->productMock->expects($this->once())->method('getTypeId')->willReturn($productType);
        $this->productMock->expects($this->never())->method('getGroupedReadonly');
        $this->productMock->expects($this->never())->method('setGroupedLinkData');
        $this->model->beforeInitializeLinks($this->subjectMock, $this->productMock, []);
    }

    /**
     * @return array
     */
    public function productTypeDataProvider()
    {
        return [
            [Type::TYPE_SIMPLE],
            [Type::TYPE_BUNDLE],
            [Type::TYPE_VIRTUAL]
        ];
    }

    /**
     * @dataProvider linksDataProvider
     */
    public function testBeforeInitializeLinksRequestHasGrouped($linksData)
    {
        $this->productMock->expects($this->once())->method('getTypeId')->willReturn(Grouped::TYPE_CODE);
        $this->productMock->expects($this->once())->method('getGroupedReadonly')->willReturn(false);
        $this->productMock->expects($this->once())->method('setProductLinks')->with($this->arrayHasKey(0));
        $this->productMock->expects($this->once())->method('getProductLinks')->willReturn([]);
        $this->productMock->expects($this->once())->method('getSku')->willReturn('sku');
        $linkedProduct = $this->createPartialMock(
            \Magento\Catalog\Model\Product::class,
            ['getGroupedReadonly', '__wakeup', 'getTypeId', 'getSku', 'getProductLinks', 'setProductLinks']
        );
        $extensionAttributes = $this->getMockBuilder(\Magento\Catalog\Api\Data\ProductLinkExtensionInterface::class)
            ->setMethods(['setQty', 'getQty'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $linkedProduct->expects($this->once())->method('getTypeId')->willReturn(Grouped::TYPE_CODE);
        $linkedProduct->expects($this->once())->method('getSku')->willReturn('sku');
        $productLink = $this->getMockBuilder(\Magento\Catalog\Api\Data\ProductLinkInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->productRepository->expects($this->once())
            ->method('getById')
            ->willReturn($linkedProduct);
        $this->productLinkFactory->expects($this->once())->method('create')->willReturn($productLink);
        $productLink->expects($this->once())->method('setSku')->with('sku')->willReturnSelf();
        $productLink->expects($this->once())->method('setLinkType')->with('associated')->willReturnSelf();
        $productLink->expects($this->once())->method('setLinkedProductSku')->with('sku')->willReturnSelf();
        $productLink->expects($this->once())->method('setLinkedProductType')
            ->with(Grouped::TYPE_CODE)
            ->willReturnSelf();
        $productLink->expects($this->once())->method('setPosition')->willReturnSelf();
        $productLink->expects($this->once())->method('getExtensionAttributes')
            ->willReturn($extensionAttributes);
        $extensionAttributes->expects($this->once())->method('setQty')->willReturnSelf();
        $this->model->beforeInitializeLinks($this->subjectMock, $this->productMock, ['associated' => $linksData]);
    }

    /**
     * @return array
     */
    public function linksDataProvider()
    {
        return [
            [[5 => ['id' => '2', 'qty' => '100', 'position' => '1']]]
        ];
    }

    public function testBeforeInitializeLinksProductIsReadonly()
    {
        $this->productMock->expects($this->once())->method('getTypeId')->willReturn(Grouped::TYPE_CODE);
        $this->productMock->expects($this->once())->method('getGroupedReadonly')->willReturn(true);
        $this->productMock->expects($this->never())->method('setGroupedLinkData');
        $this->model->beforeInitializeLinks($this->subjectMock, $this->productMock, ['associated' => 'value']);
    }
}
