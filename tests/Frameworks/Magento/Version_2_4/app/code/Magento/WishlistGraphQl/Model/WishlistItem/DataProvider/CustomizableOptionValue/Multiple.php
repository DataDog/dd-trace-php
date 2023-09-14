<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\WishlistGraphQl\Model\WishlistItem\DataProvider\CustomizableOptionValue;

use Magento\Catalog\Model\Product\Option;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Wishlist\Model\Item as WishlistItem;
use Magento\Wishlist\Model\Item\Option as SelectedOption;
use Magento\WishlistGraphQl\Model\WishlistItem\DataProvider\CustomizableOptionValueInterface;

/**
 * Multiple Option Value Data provider
 */
class Multiple implements CustomizableOptionValueInterface
{
    /**
     * Option type name
     */
    private const OPTION_TYPE = 'custom-option';

    /**
     * @var PriceUnitLabel
     */
    private $priceUnitLabel;

    /**
     * @var Uid
     */
    private $uidEncoder;

    /**
     * @param PriceUnitLabel $priceUnitLabel
     * @param Uid $uidEncoder
     */
    public function __construct(
        PriceUnitLabel $priceUnitLabel,
        Uid $uidEncoder
    ) {
        $this->priceUnitLabel = $priceUnitLabel;
        $this->uidEncoder = $uidEncoder;
    }

    /**
     * @inheritdoc
     */
    public function getData(
        WishlistItem $wishlistItem,
        Option $option,
        SelectedOption $selectedOption
    ): array {
        $selectedOptionValueData = [];
        $optionIds = explode(',', $selectedOption->getValue() ?? '');

        foreach ($optionIds as $optionId) {
            $optionValue = $option->getValueById($optionId);
            if ($optionValue) {
                $priceValueUnits = $this->priceUnitLabel->getData($optionValue->getPriceType());

                $optionDetails = [
                    self::OPTION_TYPE,
                    $option->getOptionId(),
                    $optionValue->getOptionTypeId()
                ];

                $uuid = $this->uidEncoder->encode((string)implode('/', $optionDetails));

                $selectedOptionValueData[] = [
                    'id' => $selectedOption->getId(),
                    'customizable_option_value_uid' => $uuid,
                    'label' => $optionValue->getTitle(),
                    'value' => $optionId,
                    'price' => [
                        'type' => strtoupper($optionValue->getPriceType()),
                        'units' => $priceValueUnits,
                        'value' => $optionValue->getPrice(),
                    ],
                ];
            }
        }

        return $selectedOptionValueData;
    }
}
