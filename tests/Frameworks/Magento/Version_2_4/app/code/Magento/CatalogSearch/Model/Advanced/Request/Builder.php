<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogSearch\Model\Advanced\Request;

use Magento\Framework\Search\Request\Builder as RequestBuilder;

/**
 * Catalog search advanced request builder.
 *
 * @api
 * @since 100.0.2
 */
class Builder extends RequestBuilder
{
    /**
     * Bind value to query.
     *
     * @param string $attributeCode
     * @param array|string $attributeValue
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function bindRequestValue($attributeCode, $attributeValue)
    {
        if (isset($attributeValue['from']) || isset($attributeValue['to'])) {
            if (isset($attributeValue['from']) && '' !== $attributeValue['from']) {
                $this->bind("{$attributeCode}.from", $attributeValue['from']);
            }
            if (isset($attributeValue['to']) && '' !== $attributeValue['to']) {
                $this->bind("{$attributeCode}.to", $attributeValue['to']);
            }
        } elseif (!is_array($attributeValue)) {
            $this->bind($attributeCode, $attributeValue);
        } elseif (isset($attributeValue['like'])) {
            $this->bind($attributeCode, $attributeValue['like']);
        } elseif (isset($attributeValue['in'])) {
            $this->bind($attributeCode, $attributeValue['in']);
        } elseif (isset($attributeValue['in_set'])) {
            $this->bind($attributeCode, $attributeValue['in_set']);
        }
    }
}
