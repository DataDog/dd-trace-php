<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Payment\Model\Checks;

use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Model\Quote;

/**
 * Combines several checks with logic "AND" operation.
 * Use this class to register own specifications.
 *
 * @api
 * @since 100.0.2
 */
class Composite implements SpecificationInterface
{
    /**
     * @var \Magento\Payment\Model\Checks\SpecificationInterface[]
     */
    protected $list = [];

    /**
     * @param SpecificationInterface[] $list
     */
    public function __construct(array $list)
    {
        $this->list = $list;
    }

    /**
     * Check whether payment method is applicable to quote
     *
     * @param MethodInterface $paymentMethod
     * @param \Magento\Quote\Model\Quote $quote
     * @return bool
     */
    public function isApplicable(MethodInterface $paymentMethod, Quote $quote)
    {
        foreach ($this->list as $specification) {
            if (!$specification->isApplicable($paymentMethod, $quote)) {
                return false;
            }
        }
        return true;
    }
}
