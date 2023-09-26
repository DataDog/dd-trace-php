<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Pricing\Render;

use Magento\Catalog\Model\Product;
use Magento\Framework\Json\Helper\Data;
use Magento\Framework\Math\Random;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\Render\PriceBox as PriceBoxRender;
use Magento\Framework\Pricing\Render\RendererPool;
use Magento\Framework\View\Element\Template\Context;

/**
 * Default catalog price box render
 *
 * @method string getPriceElementIdPrefix()
 * @method string getIdSuffix()
 */
class PriceBox extends PriceBoxRender
{
    /**
     * @var \Magento\Framework\Json\Helper\Data
     */
    protected $jsonHelper;

    /**
     * @var \Magento\Framework\Math\Random
     */
    protected $mathRandom;

    /**
     * @param Context $context
     * @param Product $saleableItem
     * @param PriceInterface $price
     * @param RendererPool $rendererPool
     * @param Data $jsonHelper
     * @param Random $mathRandom
     * @param array $data
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        Context $context,
        Product $saleableItem,
        PriceInterface $price,
        RendererPool $rendererPool,
        Data $jsonHelper,
        Random $mathRandom,
        array $data = []
    ) {
        $this->jsonHelper = $jsonHelper;
        $this->mathRandom = $mathRandom;
        parent::__construct($context, $saleableItem, $price, $rendererPool);
    }

    /**
     * Encode the mixed $valueToEncode into the JSON format
     *
     * @param mixed $valueToEncode
     * @return string
     */
    public function jsonEncode($valueToEncode)
    {
        return $this->jsonHelper->jsonEncode($valueToEncode);
    }

    /**
     * Get random string
     *
     * @param int $length
     * @param string|null $chars
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getRandomString($length, $chars = null)
    {
        return $this->mathRandom->getRandomString($length, $chars);
    }

    /**
     * Check if quantity can be displayed for tier price with msrp
     *
     * @param Product $product
     * @return bool
     * @SuppressWarnings(PHPMD.BooleanGetMethodName)
     */
    public function getCanDisplayQty(Product $product)
    {
        //TODO Refactor - change to const similar to Model\Product\Type\Grouped::TYPE_CODE
        if ($product->getTypeId() == 'grouped') {
            return false;
        }
        return true;
    }

    /**
     * Format percent
     *
     * @param float $percent
     *
     * @return string
     */
    public function formatPercent(float $percent): string
    {
        /*First rtrim - trim zeros. So, 10.00 -> 10.*/
        /*Second rtrim - trim dot. So, 10. -> 10*/
        return rtrim(
            rtrim(number_format($percent, 2), '0'),
            '.'
        );
    }
}
