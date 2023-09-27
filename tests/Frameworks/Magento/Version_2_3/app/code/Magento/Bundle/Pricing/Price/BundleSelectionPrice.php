<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Bundle\Pricing\Price;

use Magento\Bundle\Model\Product\Price;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price as CatalogPrice;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Pricing\Adjustment\CalculatorInterface;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\SaleableInterface;
use Magento\Framework\Pricing\Price\AbstractPrice;

/**
 * Bundle option price
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @api
 * @since 100.0.2
 */
class BundleSelectionPrice extends AbstractPrice
{
    /**
     * Price model code
     */
    const PRICE_CODE = 'bundle_selection';

    /**
     * @var \Magento\Catalog\Model\Product
     */
    protected $bundleProduct;

    /**
     * Event manager
     *
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $eventManager;

    /**
     * @var DiscountCalculator
     */
    protected $discountCalculator;

    /**
     * @var bool
     */
    protected $useRegularPrice;

    /**
     * @var Product
     */
    protected $selection;

    /**
     * Code of parent adjustment to be skipped from calculation
     *
     * @var string
     */
    protected $excludeAdjustment = null;

    /**
     * @param Product $saleableItem
     * @param float $quantity
     * @param CalculatorInterface $calculator
     * @param \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency
     * @param SaleableInterface $bundleProduct
     * @param ManagerInterface $eventManager
     * @param DiscountCalculator $discountCalculator
     * @param bool $useRegularPrice
     * @param array $excludeAdjustment
     */
    public function __construct(
        Product $saleableItem,
        $quantity,
        CalculatorInterface $calculator,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        SaleableInterface $bundleProduct,
        ManagerInterface $eventManager,
        DiscountCalculator $discountCalculator,
        $useRegularPrice = false,
        $excludeAdjustment = null
    ) {
        parent::__construct($saleableItem, $quantity, $calculator, $priceCurrency);
        $this->bundleProduct = $bundleProduct;
        $this->eventManager = $eventManager;
        $this->discountCalculator = $discountCalculator;
        $this->useRegularPrice = $useRegularPrice;
        $this->selection = $saleableItem;
        $this->excludeAdjustment = $excludeAdjustment;
    }

    /**
     * Get the price value for one of selection product.
     *
     * @return bool|float
     */
    public function getValue()
    {
        if (null !== $this->value) {
            return $this->value;
        }
        $product = $this->selection;
        $bundleSelectionKey = 'bundle-selection-'
            . ($this->useRegularPrice ? 'regular-' : '')
            . 'value-'
            . $product->getSelectionId();
        if ($product->hasData($bundleSelectionKey)) {
            return $product->getData($bundleSelectionKey);
        }

        $priceCode = $this->useRegularPrice ? BundleRegularPrice::PRICE_CODE : FinalPrice::PRICE_CODE;
        if ($this->bundleProduct->getPriceType() == Price::PRICE_TYPE_DYNAMIC) {
            // just return whatever the product's value is
            $value = $this->priceInfo
                ->getPrice($priceCode)
                ->getValue();
        } else {
            // don't multiply by quantity.  Instead just keep as quantity = 1
            $selectionPriceValue = $this->selection->getSelectionPriceValue();
            if ($this->product->getSelectionPriceType()) {
                // calculate price for selection type percent
                $price = $this->bundleProduct->getPriceInfo()
                    ->getPrice(CatalogPrice\RegularPrice::PRICE_CODE)
                    ->getValue();
                $product = clone $this->bundleProduct;
                $product->setFinalPrice($price);
                $this->eventManager->dispatch(
                    'catalog_product_get_final_price',
                    ['product' => $product, 'qty' => $this->bundleProduct->getQty()]
                );
                $price = $this->useRegularPrice ? $product->getData('price') : $product->getData('final_price');
                $value = $price * ($selectionPriceValue / 100);
            } else {
                // calculate price for selection type fixed
                $value = $this->priceCurrency->convert($selectionPriceValue);
            }
        }
        if (!$this->useRegularPrice) {
            $value = $this->discountCalculator->calculateDiscount($this->bundleProduct, $value);
        }
        $this->value = $this->priceCurrency->round($value);
        $product->setData($bundleSelectionKey, $this->value);

        return $this->value;
    }

    /**
     * Get Price Amount object
     *
     * @return AmountInterface
     */
    public function getAmount()
    {
        $product = $this->selection;
        $bundleSelectionKey = 'bundle-selection'
            . ($this->useRegularPrice ? 'regular-' : '')
            . '-amount-'
            . $product->getSelectionId();
        if ($product->hasData($bundleSelectionKey)) {
            return $product->getData($bundleSelectionKey);
        }
        $value = $this->getValue();
        if (!isset($this->amount[$value])) {
            $exclude = null;
            if ($this->getProduct()->getTypeId() == \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE) {
                $exclude = $this->excludeAdjustment;
            }
            $this->amount[$value] = $this->calculator->getAmount(
                $value,
                $this->getProduct(),
                $exclude
            );
            $product->setData($bundleSelectionKey, $this->amount[$value]);
        }

        return $this->amount[$value];
    }

    /**
     * @return SaleableInterface
     */
    public function getProduct()
    {
        if ($this->bundleProduct->getPriceType() == Price::PRICE_TYPE_DYNAMIC) {
            return parent::getProduct();
        }
        return $this->bundleProduct;
    }
}
