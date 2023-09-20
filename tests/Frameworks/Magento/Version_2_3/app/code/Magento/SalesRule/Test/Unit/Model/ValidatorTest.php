<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\SalesRule\Test\Unit\Model;

use Magento\Catalog\Helper\Data;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\Manager;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Registry;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\Validator\AbstractValidator;
use Magento\Quote\Api\Data\CartExtension;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Rule\Model\Action\Collection;
use Magento\SalesRule\Helper\CartFixedDiscount;
use Magento\SalesRule\Model\ResourceModel\Rule\Collection as RuleCollection;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\RulesApplier;
use Magento\SalesRule\Model\Utility;
use Magento\SalesRule\Model\Validator;
use Magento\SalesRule\Model\Validator\Pool;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Test sales rule model validator
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ValidatorTest extends TestCase
{
    /**
     * @var ObjectManager
     */
    protected $helper;

    /**
     * @var Validator
     */
    protected $model;

    /**
     * @var Item|MockObject
     */
    protected $item;

    /**
     * @var Address|MockObject
     */
    protected $addressMock;

    /**
     * @var RulesApplier|MockObject
     */
    protected $rulesApplier;

    /**
     * @var Pool|MockObject
     */
    protected $validators;

    /**
     * @var Utility|MockObject
     */
    protected $utility;

    /**
     * @var RuleCollection|MockObject
     */
    protected $ruleCollection;

    /**
     * @var Data|MockObject
     */
    protected $catalogData;

    /**
     * @var ManagerInterface|MockObject
     */
    protected $messageManager;

    /**
     * @var PriceCurrencyInterface|MockObject
     */
    private $priceCurrency;

    /**
     * @var CartFixedDiscount|MockObject
     */
    private $cartFixedDiscountHelper;

    protected function setUp(): void
    {
        $this->helper = new ObjectManager($this);
        $this->rulesApplier = $this->createPartialMock(
            RulesApplier::class,
            ['setAppliedRuleIds', 'applyRules', 'addDiscountDescription', '__wakeup']
        );

        $this->addressMock = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->setMethods(
                [
                    'getShippingAmountForDiscount',
                    'getBaseShippingAmountForDiscount',
                    'getQuote',
                    'getCustomAttributesCodes',
                    'setCartFixedRules'
                ]
            )
            ->getMock();

        /** @var AbstractItem|MockObject $item */
        $this->item = $this->createPartialMock(
            Item::class,
            ['__wakeup', 'getAddress', 'getParentItemId']
        );
        $this->item->expects($this->any())
            ->method('getAddress')
            ->willReturn($this->addressMock);

        $context = $this->createMock(Context::class);
        $registry = $this->createMock(Registry::class);
        $this->catalogData = $this->createMock(Data::class);
        $this->utility = $this->createMock(Utility::class);
        $this->validators = $this->createPartialMock(Pool::class, ['getValidators']);
        $this->messageManager = $this->createMock(Manager::class);
        $this->ruleCollection = $this->getMockBuilder(RuleCollection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $ruleCollectionFactoryMock = $this->prepareRuleCollectionMock($this->ruleCollection);
        $this->priceCurrency = $this->getMockBuilder(PriceCurrencyInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['roundPrice'])
            ->getMockForAbstractClass();
        $this->cartFixedDiscountHelper = $this->getMockBuilder(CartFixedDiscount::class)
            ->setMethods([
                'calculateShippingAmountWhenAppliedToShipping',
                'getDiscountAmount',
                'getShippingDiscountAmount',
                'checkMultiShippingQuote',
                'getQuoteTotalsForMultiShipping',
                'getQuoteTotalsForRegularShipping',
                'getBaseRuleTotals',
                'getAvailableDiscountAmount'])
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Validator|MockObject $validator */
        $this->model = $this->helper->getObject(
            Validator::class,
            [
                'context' => $context,
                'registry' => $registry,
                'collectionFactory' => $ruleCollectionFactoryMock,
                'catalogData' => $this->catalogData,
                'utility' => $this->utility,
                'rulesApplier' => $this->rulesApplier,
                'validators' => $this->validators,
                'messageManager' => $this->messageManager,
                'priceCurrency' => $this->priceCurrency,
                'cartFixedDiscountHelper' => $this->cartFixedDiscountHelper
            ]
        );
        $this->model->setWebsiteId(1);
        $this->model->setCustomerGroupId(2);
        $this->model->setCouponCode('code');
        $this->ruleCollection->expects($this->any())
            ->method('setValidationFilter')
            ->with(
                $this->model->getWebsiteId(),
                $this->model->getCustomerGroupId(),
                $this->model->getCouponCode(),
                null,
                $this->addressMock
            )
            ->willReturnSelf();
    }

    /**
     * @return Item|MockObject
     * @throws LocalizedException
     */
    protected function getQuoteItemMock()
    {
        $fixturePath = __DIR__ . '/_files/';
        $itemDownloadable = $this->createPartialMock(
            Item::class,
            ['getAddress', '__wakeup']
        );
        $itemDownloadable->expects($this->any())->method('getAddress')->willReturn($this->addressMock);

        $itemSimple = $this->createPartialMock(Item::class, ['getAddress', '__wakeup']);
        $itemSimple->expects($this->any())->method('getAddress')->willReturn($this->addressMock);

        /** @var $quote Quote */
        $quote = $this->createPartialMock(Quote::class, ['getStoreId', '__wakeup']);
        $quote->expects($this->any())->method('getStoreId')->willReturn(1);

        $itemData = include $fixturePath . 'quote_item_downloadable.php';
        $itemDownloadable->addData($itemData);
        $quote->addItem($itemDownloadable);

        $itemData = include $fixturePath . 'quote_item_simple.php';
        $itemSimple->addData($itemData);
        $quote->addItem($itemSimple);

        return $itemDownloadable;
    }

    public function testCanApplyRules()
    {
        $this->model->init(
            $this->model->getWebsiteId(),
            $this->model->getCustomerGroupId(),
            $this->model->getCouponCode()
        );
        $item = $this->getQuoteItemMock();
        $rule = $this->createMock(Rule::class);
        $actionsCollection = $this->createPartialMock(Collection::class, ['validate']);
        $actionsCollection->expects($this->any())
            ->method('validate')
            ->with($item)
            ->willReturn(true);
        $rule->expects($this->any())
            ->method('getActions')
            ->willReturn($actionsCollection);
        $iterator = new \ArrayIterator([$rule]);
        $this->ruleCollection->expects($this->any())
            ->method('getIterator')
            ->willReturn($iterator);

        $this->utility->expects($this->any())
            ->method('canProcessRule')
            ->with($rule, $this->anything())
            ->willReturn(true);

        $quote = $item->getQuote();
        $quote->setItemsQty(2);
        $quote->setVirtualItemsQty(1);

        $this->assertTrue($this->model->canApplyRules($item));

        $quote->setItemsQty(2);
        $quote->setVirtualItemsQty(2);

        $this->assertTrue($this->model->canApplyRules($item));
    }

    public function testProcess()
    {
        $negativePrice = -1;

        $this->item->setDiscountCalculationPrice($negativePrice);
        $this->item->setData('calculation_price', $negativePrice);

        $this->rulesApplier->expects($this->never())->method('applyRules');

        $this->model->init(
            $this->model->getWebsiteId(),
            $this->model->getCustomerGroupId(),
            $this->model->getCouponCode()
        );
        $this->model->process($this->item);
    }

    public function testProcessWhenItemPriceIsNegativeDiscountsAreZeroed()
    {
        $negativePrice = -1;
        $nonZeroDiscount = 123;
        $this->model->init(
            $this->model->getWebsiteId(),
            $this->model->getCustomerGroupId(),
            $this->model->getCouponCode()
        );

        $this->item->setDiscountCalculationPrice($negativePrice);
        $this->item->setData('calculation_price', $negativePrice);

        $this->item->setDiscountAmount($nonZeroDiscount);
        $this->item->setBaseDiscountAmount($nonZeroDiscount);
        $this->item->setDiscountPercent($nonZeroDiscount);

        $this->model->process($this->item);

        $this->assertEquals(0, $this->item->getDiscountAmount());
        $this->assertEquals(0, $this->item->getBaseDiscountAmount());
        $this->assertEquals(0, $this->item->getDiscountPercent());
    }

    public function testApplyRulesThatAppliedRuleIdsAreCollected()
    {
        $positivePrice = 1;
        $ruleId1 = 123;
        $ruleId2 = 234;
        $expectedRuleIds = [$ruleId1 => $ruleId1, $ruleId2 => $ruleId2];
        $this->model->init(
            $this->model->getWebsiteId(),
            $this->model->getCustomerGroupId(),
            $this->model->getCouponCode()
        );

        $this->item->setDiscountCalculationPrice($positivePrice);
        $this->item->setData('calculation_price', $positivePrice);
        $this->model->setSkipActionsValidation(true);

        $this->rulesApplier->expects($this->once())
            ->method('applyRules')
            ->with(
                $this->equalTo($this->item),
                $this->equalTo($this->ruleCollection),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($expectedRuleIds);
        $this->rulesApplier->expects($this->once())
            ->method('setAppliedRuleIds')
            ->with(
                $this->anything(),
                $expectedRuleIds
            );

        $this->model->process($this->item);
    }

    public function testInit()
    {
        $this->assertInstanceOf(
            Validator::class,
            $this->model->init(
                $this->model->getWebsiteId(),
                $this->model->getCustomerGroupId(),
                $this->model->getCouponCode()
            )
        );
    }

    public function testCanApplyDiscount()
    {
        $validator = $this->getMockBuilder(AbstractValidator::class)
            ->setMethods(['isValid'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->validators->expects($this->any())
            ->method('getValidators')
            ->with('discount')
            ->willReturn([$validator]);
        $validator->expects($this->any())
            ->method('isValid')
            ->with($this->item)
            ->willReturn(false);

        $this->model->init(
            $this->model->getWebsiteId(),
            $this->model->getCustomerGroupId(),
            $this->model->getCouponCode()
        );
        $this->assertFalse($this->model->canApplyDiscount($this->item));
    }

    public function testInitTotalsCanApplyDiscount()
    {
        $rule = $this->createPartialMock(
            Rule::class,
            ['getSimpleAction', 'getActions', 'getId']
        );
        $item1 = $this->getMockForAbstractClass(
            AbstractItem::class,
            [],
            '',
            false,
            true,
            true,
            [
                '__clone',
                'getDiscountCalculationPrice',
                'getBaseDiscountCalculationPrice',
                'getCalculationPrice',
                'getParentItemId',
                'getParentItem'
            ]
        );
        $item2 = clone $item1;
        $item3 = clone $item1;
        $item4 = clone $item1;
        $items = [$item1, $item2, $item3, $item4];

        $rule->expects($this->any())
            ->method('getSimpleAction')
            ->willReturn(Rule::CART_FIXED_ACTION);
        $iterator = new \ArrayIterator([$rule]);
        $this->ruleCollection->expects($this->once())->method('getIterator')->willReturn($iterator);
        $validator = $this->getMockBuilder(AbstractValidator::class)
            ->setMethods(['isValid'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->validators->expects($this->atLeastOnce())->method('getValidators')->with('discount')
            ->willReturn([$validator]);
        $validator->expects($this->at(0))->method('isValid')->with($item1)->willReturn(false);
        $validator->expects($this->at(1))->method('isValid')->with($item2)->willReturn(true);

        $item1->expects($this->any())->method('getParentItemId')->willReturn(null);
        $item1->expects($this->any())->method('getParentItem')->willReturn(null);
        $item1->expects($this->never())->method('getDiscountCalculationPrice');
        $item1->expects($this->never())->method('getBaseDiscountCalculationPrice');
        $item2->expects($this->any())->method('getParentItemId')->willReturn(null);
        $item2->expects($this->any())->method('getParentItem')->willReturn(null);
        $item2->expects($this->any())->method('getDiscountCalculationPrice')->willReturn(50);
        $item2->expects($this->once())->method('getBaseDiscountCalculationPrice')->willReturn(50);
        $item3->expects($this->any())->method('getParentItemId')->willReturn(null);
        $item3->expects($this->any())->method('getParentItem')->willReturn($item1);
        $item3->expects($this->never())->method('getDiscountCalculationPrice');
        $item3->expects($this->never())->method('getBaseDiscountCalculationPrice');
        $item4->expects($this->any())->method('getParentItemId')->willReturn(12345);
        $item4->expects($this->any())->method('getParentItem')->willReturn(null);
        $item4->expects($this->never())->method('getDiscountCalculationPrice');
        $item4->expects($this->never())->method('getBaseDiscountCalculationPrice');
        $this->utility->expects($this->once())->method('getItemQty')->willReturn(1);
        $this->utility->expects($this->any())->method('canProcessRule')->willReturn(true);

        $actionsCollection = $this->createPartialMock(Collection::class, ['validate']);
        $actionsCollection->expects($this->at(0))->method('validate')->with($item1)->willReturn(true);
        $actionsCollection->expects($this->at(1))->method('validate')->with($item2)->willReturn(true);
        $rule->expects($this->any())->method('getActions')->willReturn($actionsCollection);
        $rule->expects($this->any())->method('getId')->willReturn(1);

        $this->model->init(
            $this->model->getWebsiteId(),
            $this->model->getCustomerGroupId(),
            $this->model->getCouponCode()
        );
        $this->model->initTotals($items, $this->addressMock);
        $this->assertArrayHasKey('items_price', $this->model->getRuleItemTotalsInfo($rule->getId()));
        $this->assertArrayHasKey('base_items_price', $this->model->getRuleItemTotalsInfo($rule->getId()));
        $this->assertArrayHasKey('items_count', $this->model->getRuleItemTotalsInfo($rule->getId()));
        $this->assertEquals(1, $this->model->getRuleItemTotalsInfo($rule->getId())['items_count']);
    }

    public function testInitTotalsNoItems()
    {
        $address = $this->createMock(Address::class);
        $this->item->expects($this->never())
            ->method('getParentItemId');
        $this->model->init(
            $this->model->getWebsiteId(),
            $this->model->getCustomerGroupId(),
            $this->model->getCouponCode()
        );
        $this->model->initTotals([], $address);
    }

    /**
     * @param $ruleCollection
     * @return MockObject
     */
    protected function prepareRuleCollectionMock($ruleCollection)
    {
        $this->ruleCollection->expects($this->any())
            ->method('addFieldToFilter')
            ->with('is_active', 1)
            ->willReturnSelf();
        $this->ruleCollection->expects($this->any())
            ->method('load')
            ->willReturnSelf();

        $ruleCollectionFactoryMock =
            $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $ruleCollectionFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($ruleCollection);
        return $ruleCollectionFactoryMock;
    }

    public function testProcessShippingAmountNoRules()
    {
        $iterator = new \ArrayIterator([]);
        $this->ruleCollection->expects($this->any())
            ->method('getIterator')
            ->willReturn($iterator);
        $this->model->init(
            $this->model->getWebsiteId(),
            $this->model->getCustomerGroupId(),
            $this->model->getCouponCode()
        );
        $this->assertInstanceOf(
            Validator::class,
            $this->model->processShippingAmount($this->setupAddressMock())
        );
    }

    public function testProcessShippingAmountProcessDisabled()
    {
        $ruleMock = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMock();
        $iterator = new \ArrayIterator([$ruleMock]);
        $this->ruleCollection->expects($this->any())
            ->method('getIterator')
            ->willReturn($iterator);
        $this->model->init(
            $this->model->getWebsiteId(),
            $this->model->getCustomerGroupId(),
            $this->model->getCouponCode()
        );
        $this->assertInstanceOf(
            Validator::class,
            $this->model->processShippingAmount($this->setupAddressMock())
        );
    }

    /**
     * Tests shipping amounts according to rule simple action.
     *
     * @param string $action
     * @param int $ruleDiscount
     * @param int $shippingDiscount
     * @dataProvider dataProviderActions
     * @throws \Zend_Db_Select_Exception
     */
    public function testProcessShippingAmountActions($action, $ruleDiscount, $shippingDiscount): void
    {
        $shippingAmount = 5;
        $quoteBaseSubTotal = 10;

        $ruleMock = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->setMethods(['getApplyToShipping', 'getSimpleAction', 'getDiscountAmount'])
            ->getMock();
        $ruleMock->method('getApplyToShipping')
            ->willReturn(true);
        $ruleMock->method('getDiscountAmount')
            ->willReturn($ruleDiscount);
        $ruleMock->method('getSimpleAction')
            ->willReturn($action);

        $iterator = new \ArrayIterator([$ruleMock]);
        $this->ruleCollection->method('getIterator')
            ->willReturn($iterator);

        $this->utility->method('canProcessRule')
            ->willReturn(true);

        $this->priceCurrency->method('convert')
            ->willReturn($ruleDiscount);

        $this->priceCurrency->method('roundPrice')
            ->willReturn(round($shippingDiscount, 2));

        $this->model->init(
            $this->model->getWebsiteId(),
            $this->model->getCustomerGroupId(),
            $this->model->getCouponCode()
        );

        $addressMock = $this->setupAddressMock($shippingAmount, $quoteBaseSubTotal);

        self::assertInstanceOf(Validator::class, $this->model->processShippingAmount($addressMock));
        self::assertEquals($shippingDiscount, $addressMock->getShippingDiscountAmount());
    }

    /**
     * @return array
     */
    public static function dataProviderActions()
    {
        return [
            [Rule::TO_PERCENT_ACTION, 50, 2.5],
            [Rule::BY_PERCENT_ACTION, 50, 2.5],
            [Rule::TO_FIXED_ACTION, 5, 0],
            [Rule::BY_FIXED_ACTION, 5, 5],
            [Rule::CART_FIXED_ACTION, 5, 0],
        ];
    }

    /**
     * @param null|int $shippingAmount
     * @param null|int $quoteBaseSubTotal
     * @return MockObject
     */
    protected function setupAddressMock($shippingAmount = null, $quoteBaseSubTotal = null)
    {
        $shippingAssignments = ['test_assignment_1'];
        $storeMock = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMock();

        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'setAppliedRuleIds',
                'getStore',
                'getBaseSubtotal',
                'getExtensionAttributes',
                'isVirtual'
            ])
            ->getMock();
        $cartExtensionMock = $this->getMockBuilder(CartExtension::class)
            ->disableOriginalConstructor()
            ->setMethods(['getShippingAssignments'])
            ->getMock();

        $quoteMock->method('getStore')
            ->willReturn($storeMock);

        $quoteMock->method('setAppliedRuleIds')
            ->willReturnSelf();

        $quoteMock->method('isVirtual')
            ->willReturn(false);

        $quoteMock->method('getBaseSubtotal')
            ->willReturn($quoteBaseSubTotal);

        $this->cartFixedDiscountHelper
            ->method('getQuoteTotalsForRegularShipping')
            ->willReturn($quoteBaseSubTotal);

        $this->cartFixedDiscountHelper
            ->method('getShippingDiscountAmount')
            ->willReturn($shippingAmount);

        $quoteMock->method('getExtensionAttributes')
            ->willReturn($cartExtensionMock);

        $cartExtensionMock->method('getShippingAssignments')
            ->willReturn($shippingAssignments);

        $this->addressMock->method('getShippingAmountForDiscount')
            ->willReturn($shippingAmount);

        $this->addressMock->method('getBaseShippingAmountForDiscount')
            ->willReturn($shippingAmount);

        $this->addressMock->method('getQuote')
            ->willReturn($quoteMock);

        $this->addressMock->method('getCustomAttributesCodes')
            ->willReturn([]);

        return $this->addressMock;
    }

    public function testReset()
    {
        $this->utility->expects($this->once())
            ->method('resetRoundingDeltas');
        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->getMock();
        $addressMock = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->getMock();
        $addressMock->expects($this->once())
            ->method('getQuote')
            ->willReturn($quoteMock);
        $this->model->init(
            $this->model->getWebsiteId(),
            $this->model->getCustomerGroupId(),
            $this->model->getCouponCode()
        );
        $this->assertInstanceOf(Validator::class, $this->model->reset($addressMock));
    }
}
