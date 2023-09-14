<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CheckoutAgreements\Api;

/**
 * Interface for retrieving list of checkout agreements.
 *
 * Extended variation of CheckoutAgreementsRepositoryInterface::getList with possibility to get results according
 * search filters without predefined limitations.
 *
 * @api
 * @since 100.3.0
 */
interface CheckoutAgreementsListInterface
{
    /**
     * List of checkout agreements.
     *
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Magento\CheckoutAgreements\Api\Data\AgreementInterface[]
     * @since 100.3.0
     */
    public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria) : array;
}
