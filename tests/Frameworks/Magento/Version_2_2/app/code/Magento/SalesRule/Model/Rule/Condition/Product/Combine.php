<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\SalesRule\Model\Rule\Condition\Product;

use Magento\Catalog\Model\ResourceModel\Product\Collection;

/**
 * Combine conditions for product.
 *
 * @api
 * @since 100.0.2
 */
class Combine extends \Magento\Rule\Model\Condition\Combine
{
    /**
     * @var \Magento\SalesRule\Model\Rule\Condition\Product
     */
    protected $_ruleConditionProd;

    /**
     * @param \Magento\Rule\Model\Condition\Context $context
     * @param \Magento\SalesRule\Model\Rule\Condition\Product $ruleConditionProduct
     * @param array $data
     */
    public function __construct(
        \Magento\Rule\Model\Condition\Context $context,
        \Magento\SalesRule\Model\Rule\Condition\Product $ruleConditionProduct,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->_ruleConditionProd = $ruleConditionProduct;
        $this->setType(\Magento\SalesRule\Model\Rule\Condition\Product\Combine::class);
    }

    /**
     * Get new child select options
     *
     * @return array
     */
    public function getNewChildSelectOptions()
    {
        $productAttributes = $this->_ruleConditionProd->loadAttributeOptions()->getAttributeOption();
        $pAttributes = [];
        $iAttributes = [];
        foreach ($productAttributes as $code => $label) {
            if (strpos($code, 'quote_item_') === 0) {
                $iAttributes[] = [
                    'value' => \Magento\SalesRule\Model\Rule\Condition\Product::class . '|' . $code,
                    'label' => $label,
                ];
            } else {
                $pAttributes[] = [
                    'value' => \Magento\SalesRule\Model\Rule\Condition\Product::class . '|' . $code,
                    'label' => $label,
                ];
            }
        }

        $conditions = parent::getNewChildSelectOptions();
        $conditions = array_merge_recursive(
            $conditions,
            [
                [
                    'value' => \Magento\SalesRule\Model\Rule\Condition\Product\Combine::class,
                    'label' => __('Conditions Combination'),
                ],
                ['label' => __('Cart Item Attribute'), 'value' => $iAttributes],
                ['label' => __('Product Attribute'), 'value' => $pAttributes]
            ]
        );
        return $conditions;
    }

    /**
     * Collect validated attributes
     *
     * @param Collection $productCollection
     * @return $this
     */
    public function collectValidatedAttributes($productCollection)
    {
        foreach ($this->getConditions() as $condition) {
            $condition->collectValidatedAttributes($productCollection);
        }
        return $this;
    }

    /**
     * @inheritdoc
     */
    protected function _isValid($entity)
    {
        if (!$this->getConditions()) {
            return true;
        }

        $all = $this->getAggregator() === 'all';
        $true = (bool)$this->getValue();

        foreach ($this->getConditions() as $cond) {
            if ($entity instanceof \Magento\Framework\Model\AbstractModel) {
                $validated = $this->validateEntity($entity, $cond);
            } else {
                $validated = $cond->validateByEntityId($entity);
            }
            if ($all && $validated !== $true) {
                return false;
            } elseif (!$all && $validated === $true) {
                return true;
            }
        }

        return $all ? true : false;
    }

    /**
     * Validate entity.
     *
     * @param \Magento\Framework\Model\AbstractModel $entity
     * @param mixed $cond
     * @return bool
     */
    private function validateEntity(\Magento\Framework\Model\AbstractModel $entity, $cond): bool
    {
        $true = (bool)$this->getValue();
        $validated = !$true;
        foreach ($this->retrieveValidateEntities($entity, $cond->getAttributeScope()) as $validateEntity) {
            $validated = $cond->validate($validateEntity);
            if ($validated === $true) {
                break;
            }
        }

        return $validated;
    }

    /**
     * Retrieve entities for validation by attribute scope
     *
     * @param \Magento\Framework\Model\AbstractModel $entity
     * @param string|null $attributeScope
     * @return \Magento\Framework\Model\AbstractModel[]
     */
    private function retrieveValidateEntities(
        \Magento\Framework\Model\AbstractModel $entity,
        $attributeScope
    ): array {
        if ($attributeScope === 'parent') {
            $validateEntities = [$entity];
        } elseif ($attributeScope === 'children') {
            $validateEntities = $entity->getChildren() ?: [$entity];
        } else {
            $validateEntities = $entity->getChildren() ?: [];
            $validateEntities[] = $entity;
        }

        return $validateEntities;
    }
}
