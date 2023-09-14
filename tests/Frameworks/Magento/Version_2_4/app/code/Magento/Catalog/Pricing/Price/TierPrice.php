<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Pricing\Price;

use Magento\Catalog\Model\Product;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Pricing\Adjustment\CalculatorInterface;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\AbstractPrice;
use Magento\Framework\Pricing\Price\BasePriceProviderInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Customer\Model\Group\RetrieverInterface as CustomerGroupRetrieverInterface;

/**
 * @api
 * @since 100.0.2
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class TierPrice extends AbstractPrice implements TierPriceInterface, BasePriceProviderInterface
{
    private const XML_PATH_TAX_DISPLAY_TYPE = 'tax/display/type';

    /**
     * Price type tier
     */
    public const PRICE_CODE = 'tier_price';

    /**
     * @var Session
     * @deprecated 102.0.0
     */
    protected $customerSession;

    /**
     * @var int
     */
    protected $customerGroup;

    /**
     * Raw price list stored in DB
     *
     * @var array
     */
    protected $rawPriceList;

    /**
     * Applicable price list
     *
     * @var array
     */
    protected $priceList;

    /**
     * @var GroupManagementInterface
     */
    protected $groupManagement;

    /**
     * @var CustomerGroupRetrieverInterface
     */
    private $customerGroupRetriever;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param Product $saleableItem
     * @param float $quantity
     * @param CalculatorInterface $calculator
     * @param PriceCurrencyInterface $priceCurrency
     * @param Session $customerSession
     * @param GroupManagementInterface $groupManagement
     * @param CustomerGroupRetrieverInterface|null $customerGroupRetriever
     * @param ScopeConfigInterface|null $scopeConfig
     */
    public function __construct(
        Product $saleableItem,
        $quantity,
        CalculatorInterface $calculator,
        PriceCurrencyInterface $priceCurrency,
        Session $customerSession,
        GroupManagementInterface $groupManagement,
        CustomerGroupRetrieverInterface $customerGroupRetriever = null,
        ?ScopeConfigInterface $scopeConfig = null
    ) {
        $quantity = (float)$quantity ? $quantity : 1;
        parent::__construct($saleableItem, $quantity, $calculator, $priceCurrency);
        $this->customerSession = $customerSession;
        $this->groupManagement = $groupManagement;
        $this->customerGroupRetriever = $customerGroupRetriever
            ?? ObjectManager::getInstance()->get(CustomerGroupRetrieverInterface::class);
        if ($saleableItem->hasCustomerGroupId()) {
            $this->customerGroup = (int) $saleableItem->getCustomerGroupId();
        } else {
            $this->customerGroup = (int) $this->customerGroupRetriever->getCustomerGroupId();
        }
        $this->scopeConfig = $scopeConfig ?: ObjectManager::getInstance()->get(ScopeConfigInterface::class);
    }

    /**
     * Get price value
     *
     * @return bool|float
     */
    public function getValue()
    {
        if (null === $this->value) {
            $prices = $this->getStoredTierPrices();
            $prevQty = PriceInfoInterface::PRODUCT_QUANTITY_DEFAULT;
            $this->value = $prevPrice = $tierPrice = false;
            $priceGroup = $this->groupManagement->getAllCustomersGroup()->getId();

            foreach ($prices as $price) {
                if (!$this->canApplyTierPrice($price, $priceGroup, $prevQty)) {
                    continue;
                }
                if (false === $prevPrice || $this->isFirstPriceBetter($price['website_price'], $prevPrice)) {
                    $tierPrice = $prevPrice = $price['website_price'];
                    $prevQty = $price['price_qty'];
                    $priceGroup = $price['cust_group'];
                    $this->value = (float)$tierPrice;
                }
            }
        }
        return $this->value;
    }

    /**
     * Returns true if first price is better
     *
     * Method filters tiers price values, lower tier price value is better
     *
     * @param float $firstPrice
     * @param float $secondPrice
     * @return bool
     */
    protected function isFirstPriceBetter($firstPrice, $secondPrice)
    {
        return $firstPrice < $secondPrice;
    }

    /**
     * Returns tier price count
     *
     * @return int
     */
    public function getTierPriceCount()
    {
        return count($this->getTierPriceList());
    }

    /**
     * Returns tier price list
     *
     * @return array
     */
    public function getTierPriceList()
    {
        if (null === $this->priceList) {
            $priceList = $this->getStoredTierPrices();
            $this->priceList = $this->filterTierPrices($priceList);
            array_walk(
                $this->priceList,
                function (&$priceData) {
                    /* convert string value to float */
                    $priceData['price_qty'] *= 1;
                    $priceData['price'] = $this->applyAdjustment($priceData['price']);
                }
            );
        }

        return $this->priceList;
    }

    /**
     * Filters tier prices
     *
     * @param array $priceList
     * @return array
     */
    protected function filterTierPrices(array $priceList)
    {
        $qtyCache = [];
        $minPrice = $this->priceInfo->getPrice(FinalPrice::PRICE_CODE)->getValue();
        $allCustomersGroupId = $this->groupManagement->getAllCustomersGroup()->getId();
        foreach ($priceList as $priceKey => &$price) {
            if ($price['price'] >= $minPrice) {
                unset($priceList[$priceKey]);
                continue;
            }
            if (isset($price['price_qty']) && $price['price_qty'] == 1) {
                unset($priceList[$priceKey]);
                continue;
            }
            /* filter price by customer group */
            if ($price['cust_group'] != $this->customerGroup &&
                $price['cust_group'] != $allCustomersGroupId) {
                unset($priceList[$priceKey]);
                continue;
            }
            /* select a lower price for each quantity */
            if (isset($qtyCache[$price['price_qty']])) {
                $priceQty = $qtyCache[$price['price_qty']];
                if ($this->isFirstPriceBetter($price['website_price'], $priceList[$priceQty]['website_price'])) {
                    unset($priceList[$priceQty]);
                    $qtyCache[$price['price_qty']] = $priceKey;
                } else {
                    unset($priceList[$priceKey]);
                }
            } else {
                $qtyCache[$price['price_qty']] = $priceKey;
            }
            $minPrice = $price['price'];
        }
        return array_values($priceList);
    }

    /**
     * Returns base price
     *
     * @return float
     */
    protected function getBasePrice()
    {
        /** @var float $productPrice is a minimal available price */
        return $this->priceInfo->getPrice(BasePrice::PRICE_CODE)->getValue();
    }

    /**
     * Calculates savings percentage according to the given tier price amount and related product price amount.
     *
     * @param AmountInterface $amount
     * @return float
     */
    public function getSavePercent(AmountInterface $amount)
    {
        $productPriceAmount = $this->priceInfo->getPrice(FinalPrice::PRICE_CODE)
            ->getAmount();

        return round(100 - ((100 / $productPriceAmount->getValue()) * $amount->getValue()));
    }

    /**
     * Apply adjustment to price
     *
     * @param float|string $price
     * @return \Magento\Framework\Pricing\Amount\AmountInterface
     */
    protected function applyAdjustment($price)
    {
        return $this->calculator->getAmount($price, $this->product);
    }

    /**
     * Can apply tier price
     *
     * @param array $currentTierPrice
     * @param int $prevPriceGroup
     * @param float|string $prevQty
     * @return bool
     */
    protected function canApplyTierPrice(array $currentTierPrice, $prevPriceGroup, $prevQty)
    {
        $custGroupAllId = (int)$this->groupManagement->getAllCustomersGroup()->getId();
        // Tier price can be applied, if:
        // tier price is for current customer group or is for all groups
        if ((int)$currentTierPrice['cust_group'] !== $this->customerGroup
            && (int)$currentTierPrice['cust_group'] !== $custGroupAllId
        ) {
            return false;
        }
        // and tier qty is lower than product qty
        if ($this->quantity < $currentTierPrice['price_qty']) {
            return false;
        }
        // and tier qty is bigger than previous qty
        if ($currentTierPrice['price_qty'] < $prevQty) {
            return false;
        }
        // and found tier qty is same as previous tier qty, but current tier group isn't ALL_GROUPS
        if ($currentTierPrice['price_qty'] == $prevQty
            && $prevPriceGroup !== $custGroupAllId
            && $currentTierPrice['cust_group'] === $custGroupAllId
        ) {
            return false;
        }
        return true;
    }

    /**
     * Get clear tier price list stored in DB
     *
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function getStoredTierPrices()
    {
        if (null === $this->rawPriceList) {
            $this->rawPriceList = $this->product->getData(self::PRICE_CODE);
            if (null === $this->rawPriceList || !is_array($this->rawPriceList)) {
                /** @var \Magento\Eav\Model\Entity\Attribute\AbstractAttribute $attribute */
                $attribute = $this->product->getResource()->getAttribute(self::PRICE_CODE);
                if ($attribute) {
                    $attribute->getBackend()->afterLoad($this->product);
                    $this->rawPriceList = $this->product->getData(self::PRICE_CODE);
                }
            }
            if (null === $this->rawPriceList || !is_array($this->rawPriceList)) {
                $this->rawPriceList = [];
            }
            if (!$this->isPercentageDiscount()) {
                foreach ($this->rawPriceList as $index => $rawPrice) {
                    if (isset($rawPrice['price'])) {
                        $this->rawPriceList[$index]['price'] =
                            $this->priceCurrency->convertAndRound($rawPrice['price']);
                    }
                    if (isset($rawPrice['website_price'])) {
                        $this->rawPriceList[$index]['website_price'] =
                            $this->priceCurrency->convertAndRound($rawPrice['website_price']);
                    }
                }
            }
        }
        return $this->rawPriceList;
    }

    /**
     * Return is percentage discount
     *
     * @return bool
     */
    public function isPercentageDiscount()
    {
        return false;
    }
}
