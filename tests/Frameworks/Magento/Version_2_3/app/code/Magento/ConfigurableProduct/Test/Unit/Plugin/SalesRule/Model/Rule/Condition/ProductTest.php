<?php declare(strict_types=1);
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ConfigurableProduct\Test\Unit\Plugin\SalesRule\Model\Rule\Condition;

use Magento\Backend\Helper\Data;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Plugin\SalesRule\Model\Rule\Condition\Product as ValidatorPlugin;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Eav\Model\Config;
use Magento\Eav\Model\Entity\AbstractEntity;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\Collection;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Locale\Format;
use Magento\Framework\Locale\FormatInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Rule\Model\Condition\Context;
use Magento\SalesRule\Model\Rule\Condition\Product as SalesRuleProduct;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class ProductTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    private $objectManager;

    /**
     * @var SalesRuleProduct
     */
    private $validator;

    /**
     * @var \Magento\ConfigurableProduct\Plugin\SalesRule\Model\Rule\Condition\Product
     */
    private $validatorPlugin;

    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->validator = $this->createValidator();
        $this->validatorPlugin = $this->objectManager->getObject(ValidatorPlugin::class);
    }

    /**
     * @return \Magento\SalesRule\Model\Rule\Condition\Product
     */
    private function createValidator(): SalesRuleProduct
    {
        /** @var Context|\PHPUnit\Framework\MockObject\MockObject $contextMock */
        $contextMock = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Data|\PHPUnit\Framework\MockObject\MockObject $backendHelperMock */
        $backendHelperMock = $this->getMockBuilder(Data::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Config|\PHPUnit\Framework\MockObject\MockObject $configMock */
        $configMock = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var ProductFactory|\PHPUnit\Framework\MockObject\MockObject $productFactoryMock */
        $productFactoryMock = $this->getMockBuilder(ProductFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var ProductRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject $productRepositoryMock */
        $productRepositoryMock = $this->getMockBuilder(ProductRepositoryInterface::class)
            ->getMockForAbstractClass();
        $attributeLoaderInterfaceMock = $this->getMockBuilder(AbstractEntity::class)
            ->disableOriginalConstructor()
            ->setMethods(['getAttributesByCode'])
            ->getMock();
        $attributeLoaderInterfaceMock
            ->expects($this->any())
            ->method('getAttributesByCode')
            ->willReturn([]);
        /** @var Product|\PHPUnit\Framework\MockObject\MockObject $productMock */
        $productMock = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->setMethods(['loadAllAttributes', 'getConnection', 'getTable'])
            ->getMock();
        $productMock->expects($this->any())
        ->method('loadAllAttributes')
        ->willReturn($attributeLoaderInterfaceMock);
        /** @var Collection|\PHPUnit\Framework\MockObject\MockObject $collectionMock */
        $collectionMock = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var FormatInterface|\PHPUnit\Framework\MockObject\MockObject $formatMock */
        $formatMock = new Format(
            $this->getMockBuilder(ScopeResolverInterface::class)->disableOriginalConstructor()->getMockForAbstractClass(),
            $this->getMockBuilder(ResolverInterface::class)->disableOriginalConstructor()->getMockForAbstractClass(),
            $this->getMockBuilder(CurrencyFactory::class)->disableOriginalConstructor()->getMock()
        );

        return new SalesRuleProduct(
            $contextMock,
            $backendHelperMock,
            $configMock,
            $productFactoryMock,
            $productRepositoryMock,
            $productMock,
            $collectionMock,
            $formatMock
        );
    }

    public function testChildIsUsedForValidation()
    {
        $configurableProductMock = $this->createProductMock();
        $configurableProductMock
            ->expects($this->any())
            ->method('getTypeId')
            ->willReturn(Configurable::TYPE_CODE);
        $configurableProductMock
            ->expects($this->any())
            ->method('hasData')
            ->with($this->equalTo('special_price'))
            ->willReturn(false);

        /* @var AbstractItem|\PHPUnit\Framework\MockObject\MockObject $item */
        $item = $this->getMockBuilder(AbstractItem::class)
            ->disableOriginalConstructor()
            ->setMethods(['setProduct', 'getProduct', 'getChildren'])
            ->getMockForAbstractClass();
        $item->expects($this->any())
            ->method('getProduct')
            ->willReturn($configurableProductMock);

        $simpleProductMock = $this->createProductMock();
        $simpleProductMock
            ->expects($this->any())
            ->method('getTypeId')
            ->willReturn(Type::TYPE_SIMPLE);
        $simpleProductMock
            ->expects($this->any())
            ->method('hasData')
            ->with($this->equalTo('special_price'))
            ->willReturn(true);

        $childItem = $this->getMockBuilder(AbstractItem::class)
            ->disableOriginalConstructor()
            ->setMethods(['getProduct'])
            ->getMockForAbstractClass();
        $childItem->expects($this->any())
            ->method('getProduct')
            ->willReturn($simpleProductMock);

        $item->expects($this->any())
            ->method('getChildren')
            ->willReturn([$childItem]);
        $item->expects($this->once())
            ->method('setProduct')
            ->with($simpleProductMock);

        $this->validator->setAttribute('special_price');

        $this->validatorPlugin->beforeValidate($this->validator, $item);
    }

    /**
     * @return Product|\PHPUnit\Framework\MockObject\MockObject
     */
    private function createProductMock(): \PHPUnit\Framework\MockObject\MockObject
    {
        $productMock = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->setMethods(
                [
                    'getAttribute',
                    'getId',
                    'setQuoteItemQty',
                    'setQuoteItemPrice',
                    'getTypeId',
                    'hasData',
                ]
            )
            ->getMock();
        $productMock
            ->expects($this->any())
            ->method('setQuoteItemQty')
            ->willReturnSelf();
        $productMock
            ->expects($this->any())
            ->method('setQuoteItemPrice')
            ->willReturnSelf();

        return $productMock;
    }

    public function testChildIsNotUsedForValidation()
    {
        $simpleProductMock = $this->createProductMock();
        $simpleProductMock
            ->expects($this->any())
            ->method('getTypeId')
            ->willReturn(Type::TYPE_SIMPLE);
        $simpleProductMock
            ->expects($this->any())
            ->method('hasData')
            ->with($this->equalTo('special_price'))
            ->willReturn(true);

        /* @var AbstractItem|\PHPUnit\Framework\MockObject\MockObject $item */
        $item = $this->getMockBuilder(AbstractItem::class)
            ->disableOriginalConstructor()
            ->setMethods(['setProduct', 'getProduct'])
            ->getMockForAbstractClass();
        $item->expects($this->any())
            ->method('getProduct')
            ->willReturn($simpleProductMock);

        $this->validator->setAttribute('special_price');

        $this->validatorPlugin->beforeValidate($this->validator, $item);
    }

    /**
     * Test for Configurable product in invalid state with no children does not raise error
     */
    public function testChildIsNotUsedForValidationWhenConfigurableProductIsMissingChildren()
    {
        $configurableProductMock = $this->createProductMock();
        $configurableProductMock
            ->expects($this->any())
            ->method('getTypeId')
            ->willReturn(Configurable::TYPE_CODE);

        $configurableProductMock
            ->expects($this->any())
            ->method('hasData')
            ->with($this->equalTo('special_price'))
            ->willReturn(false);

        /* @var AbstractItem|\PHPUnit\Framework\MockObject\MockObject $item */
        $item = $this->getMockBuilder(AbstractItem::class)
            ->disableOriginalConstructor()
            ->setMethods(['setProduct', 'getProduct', 'getChildren'])
            ->getMockForAbstractClass();
        $item->expects($this->any())
            ->method('getProduct')
            ->willReturn($configurableProductMock);
        $item->expects($this->any())
            ->method('getChildren')
            ->willReturn([]);

        $this->validator->setAttribute('special_price');

        $this->validatorPlugin->beforeValidate($this->validator, $item);
    }
}
