<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Sales\Api;

/**
 * Credit memo comment repository interface.
 *
 * After a customer places and pays for an order and an invoice has been issued, the merchant can create a credit memo
 * to refund all or part of the amount paid for any returned or undelivered items. The memo restores funds to the
 * customer account so that the customer can make future purchases. A credit memo usually includes comments that detail
 * why the credit memo amount was credited to the customer.
 * @api
 * @since 100.0.2
 */
interface CreditmemoCommentRepositoryInterface
{
    /**
     * Loads a specified credit memo comment.
     *
     * @param int $id The credit memo comment ID.
     * @return \Magento\Sales\Api\Data\CreditmemoCommentInterface Credit memo comment interface.
     */
    public function get($id);

    /**
     * Lists credit memo comments that match specified search criteria.
     *
     * Returns a credit memo comment search results interface.
     *
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria The search criteria.
     * @return \Magento\Sales\Api\Data\CreditmemoCommentSearchResultInterface
     * Credit memo comment search results interface.
     */
    public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria);

    /**
     * Deletes a specified credit memo comment.
     *
     * @param \Magento\Sales\Api\Data\CreditmemoCommentInterface $entity The credit memo comment.
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(\Magento\Sales\Api\Data\CreditmemoCommentInterface $entity);

    /**
     * Performs persist operations for a specified entity.
     *
     * @param \Magento\Sales\Api\Data\CreditmemoCommentInterface $entity The credit memo comment.
     * @return \Magento\Sales\Api\Data\CreditmemoCommentInterface Credit memo comment interface.
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(\Magento\Sales\Api\Data\CreditmemoCommentInterface $entity);
}
