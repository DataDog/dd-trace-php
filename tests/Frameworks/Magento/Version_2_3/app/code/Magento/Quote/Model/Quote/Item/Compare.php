<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Quote\Model\Quote\Item;

use Magento\Quote\Model\Quote\Item;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Serialize\JsonValidator;

/**
 * Compare quote items
 */
class Compare
{
    /**
     * @var Json
     */
    private $serializer;

    /**
     * @var JsonValidator
     */
    private $jsonValidator;

    /**
     * Constructor
     *
     * @param Json|null $serializer
     * @param JsonValidator|null $jsonValidator
     */
    public function __construct(
        Json $serializer = null,
        JsonValidator $jsonValidator = null
    ) {
        $this->serializer = $serializer ?: ObjectManager::getInstance()->get(Json::class);
        $this->jsonValidator = $jsonValidator ?: ObjectManager::getInstance()->get(JsonValidator::class);
    }

    /**
     * Returns option values adopted to compare
     *
     * @param mixed $value
     * @return mixed
     */
    protected function getOptionValues($value)
    {
        if (is_string($value) && $this->jsonValidator->isValid($value)) {
            $value = $this->serializer->unserialize($value);
            if (is_array($value)) {
                unset($value['qty'], $value['uenc'], $value['related_product'], $value['item']);
                $value = array_filter($value, function ($optionValue) {
                    return !empty($optionValue);
                });
            }
        }
        return $value;
    }

    /**
     * Compare two quote items
     *
     * @param Item $target
     * @param Item $compared
     * @return bool
     */
    public function compare(Item $target, Item $compared)
    {
        if ($target->getProductId() != $compared->getProductId()) {
            return false;
        }

        $targetOptionByCode = $target->getOptionsByCode();
        $comparedOptionsByCode = $compared->getOptionsByCode();
        if (!$target->compareOptions($targetOptionByCode, $comparedOptionsByCode)) {
            return false;
        }
        if (!$target->compareOptions($comparedOptionsByCode, $targetOptionByCode)) {
            return false;
        }
        return true;
    }

    /**
     * Returns options adopted to compare
     *
     * @param Item $item
     * @return array
     */
    public function getOptions(Item $item)
    {
        $options = [];
        foreach ($item->getOptions() as $option) {
            $options[$option->getCode()] = $this->getOptionValues($option->getValue());
        }
        return $options;
    }
}
