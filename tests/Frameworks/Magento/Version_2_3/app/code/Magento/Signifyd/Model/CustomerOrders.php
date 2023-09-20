<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Model;

use Exception;
use Magento\Directory\Model\Currency;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides information about customer orders.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @deprecated 100.3.5 Starting from Magento 2.3.5 Signifyd core integration is deprecated in favor of
 * official Signifyd integration available on the marketplace
 */
class CustomerOrders
{
    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var FilterBuilder
     */
    private $filterBuilder;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var CurrencyFactory
     */
    private $currencyFactory;

    /**
     * @var array
     */
    private $currencies = [];

    /**
     * @var string
     */
    private static $usdCurrencyCode = 'USD';

    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param OrderRepositoryInterface $orderRepository
     * @param CurrencyFactory $currencyFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        FilterBuilder $filterBuilder,
        OrderRepositoryInterface $orderRepository,
        CurrencyFactory $currencyFactory,
        LoggerInterface $logger
    ) {
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->filterBuilder = $filterBuilder;
        $this->orderRepository = $orderRepository;
        $this->currencyFactory = $currencyFactory;
        $this->logger = $logger;
    }

    /**
     * Returns aggregated customer orders count and total amount in USD.
     *
     * Returned array contains next keys:
     * aggregateOrderCount - total count of orders placed by this account since it was created, including the current
     * aggregateOrderDollars - total amount spent by this account since it was created, including the current order
     *
     * @param int $customerId
     * @return array
     */
    public function getAggregatedOrdersInfo($customerId)
    {
        $result = [
            'aggregateOrderCount' => null,
            'aggregateOrderDollars' => null
        ];

        $customerOrders = $this->getCustomerOrders($customerId);
        if (!empty($customerOrders)) {
            try {
                $orderTotalDollars = 0.0;
                foreach ($customerOrders as $order) {
                    $orderTotalDollars += $this->getUsdOrderTotal(
                        $order->getBaseGrandTotal(),
                        $order->getBaseCurrencyCode()
                    );
                }
                $result = [
                    'aggregateOrderCount' => count($customerOrders),
                    'aggregateOrderDollars' => $orderTotalDollars
                ];
            } catch (Exception $e) {
                $this->logger->error($e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Returns customer orders.
     *
     * @param int $customerId
     * @return OrderInterface[]
     */
    private function getCustomerOrders($customerId)
    {
        $filters = [
            $this->filterBuilder->setField(OrderInterface::CUSTOMER_ID)->setValue($customerId)->create()
        ];
        $this->searchCriteriaBuilder->addFilters($filters);
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $searchResults = $this->orderRepository->getList($searchCriteria);

        return $searchResults->getItems();
    }

    /**
     * Returns amount in USD.
     *
     * @param float $amount
     * @param string $currency
     * @return float
     */
    private function getUsdOrderTotal($amount, $currency)
    {
        if ($currency === self::$usdCurrencyCode) {
            return $amount;
        }

        $operationCurrency = $this->getCurrencyByCode($currency);

        return $operationCurrency->convert($amount, self::$usdCurrencyCode);
    }

    /**
     * Returns currency by currency code.
     *
     * @param string|null $currencyCode
     * @return Currency
     */
    private function getCurrencyByCode($currencyCode)
    {
        if (isset($this->currencies[$currencyCode])) {
            return $this->currencies[$currencyCode];
        }

        /** @var Currency $currency */
        $currency = $this->currencyFactory->create();
        $this->currencies[$currencyCode] = $currency->load($currencyCode);

        return $this->currencies[$currencyCode];
    }
}
