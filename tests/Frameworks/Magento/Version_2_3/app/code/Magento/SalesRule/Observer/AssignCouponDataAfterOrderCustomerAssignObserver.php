<?php

/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\SalesRule\Observer;

use Magento\Framework\Event\Observer;
use Magento\SalesRule\Model\Coupon\UpdateCouponUsages;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Framework\Event\ObserverInterface;

/**
 * Assign coupon data after order customer assign.
 */
class AssignCouponDataAfterOrderCustomerAssignObserver implements ObserverInterface
{
    private const EVENT_KEY_ORDER = 'order';

    /**
     * @var UpdateCouponUsages
     */
    private $updateCouponUsages;

    /**
     * AssignCouponDataAfterOrderCustomerAssign constructor.
     *
     * @param UpdateCouponUsages $updateCouponUsages
     */
    public function __construct(
        UpdateCouponUsages $updateCouponUsages
    ) {
        $this->updateCouponUsages = $updateCouponUsages;
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        $event = $observer->getEvent();
        /** @var OrderInterface $order */
        $order = $event->getData(self::EVENT_KEY_ORDER);
        if (!$order->getCustomerId()) {
            return;
        }

        $this->updateCouponUsages->execute($order, true);
    }
}
