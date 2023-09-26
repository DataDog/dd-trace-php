<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Ui\DataProvider\Product\Listing;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\Reporting;
use Magento\Store\Model\StoreManager;

/**
 * Provide information about current store and currency for product listing ui component
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DataProvider extends \Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider
{
    /**
     * @var StoreManager
     */
    private $storeManager;

    /**
     * Core store config
     *
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param string $name
     * @param Reporting $reporting
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param RequestInterface $request
     * @param FilterBuilder $filterBuilder
     * @param StoreManager $storeManager
     * @param array $meta
     * @param array $data
     */
    public function __construct(
        $name,
        Reporting $reporting,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RequestInterface $request,
        FilterBuilder $filterBuilder,
        StoreManager $storeManager,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct(
            $name,
            '',
            '',
            $reporting,
            $searchCriteriaBuilder,
            $request,
            $filterBuilder,
            $meta,
            $data
        );

        $this->name = $name;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $data['config']['scopeConfig'];
    }

    /**
     * @inheritdoc
     */
    public function getData()
    {
        $data = [];
        $store = $this->storeManager->getStore();
        $productsScope = $this->scopeConfig->getValue(
            'catalog/recently_products/scope',
            \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITE
        );
        $data['store'] = $store->getId();
        $data['currency'] = $store->getCurrentCurrency()->getCode();
        $data['productCurrentScope'] = $productsScope;
        return $data;
    }
}
