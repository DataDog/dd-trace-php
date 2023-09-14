<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\SalesRule\Api;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\RuleRepository;
use Magento\TestFramework\TestCase\WebapiAbstract;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RuleRepositoryTest extends WebapiAbstract
{
    public const SERVICE_NAME = 'salesRuleRuleRepositoryV1';
    public const RESOURCE_PATH = '/V1/salesRules';
    public const SERVICE_VERSION = "V1";

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
    }

    protected function getSalesRuleData()
    {
        $data = [
            'name' => '40% Off on Large Orders',
            'store_labels' => [
                [
                    'store_id' => 0,
                    'store_label' => 'TestRule_Label',
                ],
                [
                    'store_id' => 1,
                    'store_label' => 'TestRule_Label_default',
                ],
            ],
            'description' => 'Test sales rule',
            'website_ids' => [1],
            'customer_group_ids' => [0, 1, 3],
            'uses_per_customer' => 2,
            'is_active' => true,
            'condition' => [
                'condition_type' => \Magento\SalesRule\Model\Rule\Condition\Combine::class,
                'conditions' => [
                    [
                        'condition_type' => \Magento\SalesRule\Model\Rule\Condition\Address::class,
                        'operator' => '>',
                        'attribute_name' => 'base_subtotal',
                        'value' => 800
                    ]
                ],
                'aggregator_type' => 'all',
                'operator' => null,
                'value' => null,
            ],
            'action_condition' => [
                'condition_type' => \Magento\SalesRule\Model\Rule\Condition\Product\Combine::class,
                "conditions" => [
                    [
                        'condition_type' => \Magento\SalesRule\Model\Rule\Condition\Product::class,
                        'operator' => '==',
                        'attribute_name' => 'attribute_set_id',
                        'value' => '4',
                    ]
                ],
                'aggregator_type' => 'all',
                'operator' => null,
                'value' => null,
            ],
            'stop_rules_processing' => true,
            'is_advanced' => true,
            'sort_order' => 2,
            'simple_action' => 'cart_fixed',
            'discount_amount' => 40,
            'discount_qty' => 2,
            'discount_step' => 0,
            'apply_to_shipping' => false,
            'times_used' => 1,
            'is_rss' => true,
            'coupon_type' => \Magento\SalesRule\Api\Data\RuleInterface::COUPON_TYPE_SPECIFIC_COUPON,
            'use_auto_generation' => false,
            'uses_per_coupon' => 0,
            'simple_free_shipping' => 0,
            'from_date' => '2022-04-22',
            'to_date' => '2022-09-25',
        ];
        return $data;
    }

    public function testCrud()
    {
        //test create
        $inputData = $this->getSalesRuleData();
        $result = $this->createRule($inputData);
        $ruleId = $result['rule_id'];
        $this->assertArrayHasKey('rule_id', $result);
        $this->assertEquals($ruleId, $result['rule_id']);
        $this->assertEquals($inputData['from_date'], $result['from_date']);
        $this->assertEquals($inputData['to_date'], $result['to_date']);
        unset($result['rule_id']);
        unset($result['extension_attributes']);
        $this->assertEquals($inputData, $result);

        //test getList
        $result = $this->verifyGetList($ruleId);
        unset($result['rule_id']);
        unset($result['extension_attributes']);
        $this->assertEquals($inputData, $result);

        //test update
        $inputData['times_used'] = 2;
        $inputData['customer_group_ids'] = [0, 1, 3];
        $inputData['discount_amount'] = 30;
        $result = $this->updateRule($ruleId, $inputData);
        unset($result['rule_id']);
        unset($result['extension_attributes']);
        $this->assertEquals($inputData, $result);

        //test get
        $result = $this->getRule($ruleId);
        unset($result['rule_id']);
        unset($result['extension_attributes']);
        $this->assertEquals($inputData, $result);

        //test delete
        $this->assertTrue($this->deleteRule($ruleId));
    }

    public function verifyGetList($ruleId)
    {
        $searchCriteria = [
            'searchCriteria' => [
                'filter_groups' => [
                    [
                        'filters' => [
                            [
                                'field' => 'rule_id',
                                'value' => $ruleId,
                                'condition_type' => 'eq',
                            ],
                        ],
                    ],
                ],
                'current_page' => 1,
                'page_size' => 2,
            ],
        ];

        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/search' . '?' . http_build_query($searchCriteria),
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_GET,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'GetList',
            ],
        ];

        $response = $this->_webApiCall($serviceInfo, $searchCriteria);

        $this->assertArrayHasKey('search_criteria', $response);
        $this->assertArrayHasKey('total_count', $response);
        $this->assertArrayHasKey('items', $response);

        $this->assertEquals($searchCriteria['searchCriteria'], $response['search_criteria']);
        $this->assertTrue($response['total_count'] > 0);
        $this->assertTrue(count($response['items']) > 0);

        $this->assertNotNull($response['items'][0]['rule_id']);
        $this->assertEquals($ruleId, $response['items'][0]['rule_id']);

        return $response['items'][0];
    }

    /**
     * @magentoApiDataFixture Magento/SalesRule/_files/rules_advanced.php
     */
    public function testGetListWithMultipleFiltersAndSorting()
    {
        /** @var $searchCriteriaBuilder  \Magento\Framework\Api\SearchCriteriaBuilder */
        $searchCriteriaBuilder = $this->objectManager->create(
            \Magento\Framework\Api\SearchCriteriaBuilder::class
        );
        /** @var $filterBuilder  \Magento\Framework\Api\FilterBuilder */
        $filterBuilder = $this->objectManager->create(
            \Magento\Framework\Api\FilterBuilder::class
        );
        /** @var \Magento\Framework\Api\SortOrderBuilder $sortOrderBuilder */
        $sortOrderBuilder = $this->objectManager->create(
            \Magento\Framework\Api\SortOrderBuilder::class
        );

        $filter1 = $filterBuilder->setField('is_rss')
            ->setValue(1)
            ->setConditionType('eq')
            ->create();
        $filter2 = $filterBuilder->setField('name')
            ->setValue('#4')
            ->setConditionType('eq')
            ->create();
        $filter3 = $filterBuilder->setField('uses_per_coupon')
            ->setValue(1)
            ->setConditionType('gt')
            ->create();
        $sortOrder = $sortOrderBuilder->setField('name')
            ->setDirection('ASC')
            ->create();

        $searchCriteriaBuilder->addFilters([$filter1, $filter2]);
        $searchCriteriaBuilder->addFilters([$filter3]);
        $searchCriteriaBuilder->addSortOrder($sortOrder);
        $searchCriteriaBuilder->setPageSize(20);
        $searchData = $searchCriteriaBuilder->create()->__toArray();

        $requestData = ['searchCriteria' => $searchData];

        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/search?' . http_build_query($requestData),
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_GET,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'GetList',
            ],
        ];

        $result = $this->_webApiCall($serviceInfo, $requestData);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('search_criteria', $result);
        $this->assertCount(2, $result['items']);
        $this->assertEquals('#1', $result['items'][0]['name']);
        $this->assertEquals('#2', $result['items'][1]['name']);
        $this->assertEquals($searchData, $result['search_criteria']);
    }

    /**
     * @magentoApiDataFixture Magento/SalesRule/_files/cart_rule_100_percent_off_with_coupon.php
     */
    public function testUpdateRuleHavingSpecificCouponCode(): void
    {
        $searchCriteria = $this->objectManager->create(SearchCriteriaBuilder::class)
            ->addFilter('name', '100% discount on orders for registered customers')
            ->create();
        $salesRules = $this->objectManager->create(RuleRepositoryInterface::class)
            ->getList($searchCriteria)
            ->getItems();
        $ruleId = array_pop($salesRules)->getRuleId();
        $inputData = $this->getSalesRuleData();
        unset($inputData['name']);
        $result = $this->updateRule($ruleId, $inputData);
        $this->assertNotEmpty($result);
        $coupon = $this->objectManager->create(Coupon::class);
        $coupon->loadByCode('free_use');
        $this->assertInstanceOf(Coupon::class, $coupon);
        $this->assertEquals($ruleId, $coupon->getRuleId());
    }

    /**
     * Create Sales rule
     *
     * @param $rule
     * @return array
     */
    protected function createRule($rule)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH,
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_POST
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'Save',
            ],
        ];
        $requestData = ['rule' => $rule];
        return $this->_webApiCall($serviceInfo, $requestData);
    }

    /**
     * @param int $id
     * @return bool
     * @throws \Exception
     */
    protected function deleteRule($id)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $id,
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_DELETE,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'DeleteById',
            ],
        ];

        return $this->_webApiCall($serviceInfo, ['rule_id' => $id]);
    }

    protected function updateRule($id, $data)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $id,
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_PUT,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'Save',
            ],
        ];

        $data['rule_id'] = $id;
        return $this->_webApiCall($serviceInfo, ['rule_id' => $id, 'rule' => $data]);
    }

    protected function getRule($id)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $id,
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_GET,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'GetById',
            ],
        ];

        return $this->_webApiCall($serviceInfo, ['rule_id' => $id]);
    }
}
