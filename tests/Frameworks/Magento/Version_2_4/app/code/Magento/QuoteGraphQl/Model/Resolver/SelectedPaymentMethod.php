<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\QuoteGraphQl\Model\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * @inheritdoc
 */
class SelectedPaymentMethod implements ResolverInterface
{
    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (!isset($value['model'])) {
            throw new LocalizedException(__('"model" value should be specified'));
        }

        /** @var \Magento\Quote\Model\Quote $cart */
        $cart = $value['model'];

        $payment = $cart->getPayment();
        if (!$payment) {
            return [];
        }

        try {
            $methodTitle = $payment->getMethodInstance()->getTitle();
        } catch (LocalizedException $e) {
            $methodTitle = '';
        }

        return [
            'code' => $payment->getMethod() ?? '',
            'title' => $methodTitle,
            'purchase_order_number' => $payment->getPoNumber(),
        ];
    }
}
