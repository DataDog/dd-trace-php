<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Checkout\Test\Constraint;

use Magento\Checkout\Test\Page\CheckoutOnepage;
use Magento\Mtf\Constraint\AbstractConstraint;

/**
 * Assert that Order Grand Total is correct on checkoutOnePage.
 */
class AssertGrandTotalOrderReview extends AbstractConstraint
{
    /**
     * Wait element.
     *
     * @var string
     */
    protected $waitElement = '.loading-mask';

    /**
     * Assert that Order Grand Total is correct on checkoutOnePage
     *
     * @param CheckoutOnepage $checkoutOnepage
     * @param string $grandTotal
     * @return void
     */
    public function processAssert(CheckoutOnepage $checkoutOnepage, $grandTotal)
    {
        $checkoutOnepage->getReviewBlock()->waitForElementNotVisible($this->waitElement);
        $checkoutReviewGrandTotal = $checkoutOnepage->getReviewBlock()->getGrandTotal();

        \PHPUnit_Framework_Assert::assertEquals(
            number_format($grandTotal, 2),
            $checkoutReviewGrandTotal,
            "Grand Total price: $checkoutReviewGrandTotal not equals with price from data set: $grandTotal"
        );
    }

    /**
     * Returns a string representation of the object
     *
     * @return string
     */
    public function toString()
    {
        return 'Grand Total price equals to price from data set.';
    }
}
