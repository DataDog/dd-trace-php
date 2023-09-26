<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Sales\Api;

/**
 * Order status history repository interface.
 *
 * An order is a document that a web store issues to a customer. Magento generates a sales order that lists the product
 * items, billing and shipping addresses, and shipping and payment methods. A corresponding external document, known as
 * a purchase order, is emailed to the customer.
 * @api
 * @since 100.0.2
 */
interface OrderStatusHistoryRepositoryInterface
{
    /**
     * Lists order status history comments that match specified search criteria.
     *
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria The search criteria.
     * @return \Magento\Sales\Api\Data\OrderStatusHistorySearchResultInterface Order status history
     * search result interface.
     */
    public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria);

    /**
     * Loads a specified order status comment.
     *
     * @param int $id The order status comment ID.
     * @return \Magento\Sales\Api\Data\OrderStatusHistoryInterface Order status history interface.
     */
    public function get($id);

    /**
     * Deletes a specified order status comment.
     *
     * @param \Magento\Sales\Api\Data\OrderStatusHistoryInterface $entity The order status comment.
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(\Magento\Sales\Api\Data\OrderStatusHistoryInterface $entity);

    /**
     * Performs persist operations for a specified order status comment.
     *
     * @param \Magento\Sales\Api\Data\OrderStatusHistoryInterface $entity The order status comment.
     * @return \Magento\Sales\Api\Data\OrderStatusHistoryInterface Order status history interface.
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(\Magento\Sales\Api\Data\OrderStatusHistoryInterface $entity);
}
