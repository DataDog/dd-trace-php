<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Customer\Test\Unit\Model\ResourceModel\Group\Grid;

use Magento\Framework\Api\SearchCriteria;
use Magento\Customer\Model\ResourceModel\Group\Grid\ServiceCollection;
use Magento\Framework\Api\SortOrder;

/**
 * Unit test for \Magento\Customer\Model\ResourceModel\Group\Grid\ServiceCollection
 */
class ServiceCollectionTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager  */
    protected $objectManager;

    /** @var \Magento\Framework\Api\FilterBuilder */
    protected $filterBuilder;

    /** @var \Magento\Framework\Api\SearchCriteriaBuilder */
    protected $searchCriteriaBuilder;

    /** @var \Magento\Framework\Api\SortOrderBuilder */
    protected $sortOrderBuilder;

    /** @var \Magento\Customer\Api\Data\GroupSearchResultsInterface */
    protected $searchResults;

    /** @var \PHPUnit\Framework\MockObject\MockObject| */
    protected $groupRepositoryMock;

    /** @var ServiceCollection */
    protected $serviceCollection;

    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->filterBuilder = $this->objectManager->getObject(\Magento\Framework\Api\FilterBuilder::class);
        $filterGroupBuilder = $this->objectManager
            ->getObject(\Magento\Framework\Api\Search\FilterGroupBuilder::class);
        /** @var \Magento\Framework\Api\SearchCriteriaBuilder $searchBuilder */
        $this->searchCriteriaBuilder = $this->objectManager->getObject(
            \Magento\Framework\Api\SearchCriteriaBuilder::class,
            ['filterGroupBuilder' => $filterGroupBuilder]
        );
        $this->sortOrderBuilder = $this->objectManager->getObject(
            \Magento\Framework\Api\SortOrderBuilder::class
        );
        $this->groupRepositoryMock = $this->getMockBuilder(\Magento\Customer\Api\GroupRepositoryInterface::class)
            ->getMock();

        $this->searchResults = $this->createMock(
            \Magento\Framework\Api\SearchResultsInterface::class
        );

        $this->searchResults
            ->expects($this->any())
            ->method('getTotalCount');
        $this->searchResults
            ->expects($this->any())
            ->method('getItems')
            ->willReturn($this->returnValue([]));

        $this->serviceCollection = $this->objectManager
            ->getObject(
                \Magento\Customer\Model\ResourceModel\Group\Grid\ServiceCollection::class,
                [
                    'filterBuilder' => $this->filterBuilder,
                    'searchCriteriaBuilder' => $this->searchCriteriaBuilder,
                    'groupRepository' => $this->groupRepositoryMock,
                    'sortOrderBuilder' => $this->sortOrderBuilder,
                ]
            );
    }

    public function testGetSearchCriteriaImplicitEq()
    {
        $sortOrder = $this->sortOrderBuilder
            ->setField('name')
            ->setDirection(SortOrder::SORT_ASC)
            ->create();
        /** @var SearchCriteria $expectedSearchCriteria */
        $expectedSearchCriteria = $this->searchCriteriaBuilder
            ->setCurrentPage(1)
            ->setPageSize(false)
            ->addSortOrder($sortOrder)
            ->addFilters(
                [$this->filterBuilder->setField('name')->setConditionType('eq')->setValue('Magento')->create()]
            )->create();

        // Verifies that the search criteria Data Object created by the serviceCollection matches expected
        $this->groupRepositoryMock->expects($this->once())
            ->method('getList')
            ->with($this->equalTo($expectedSearchCriteria))
            ->willReturn($this->searchResults);

        // Now call service collection to load the data.  This causes it to create the search criteria Data Object
        $this->serviceCollection->addFieldToFilter('name', 'Magento');
        $this->serviceCollection->setOrder('name', ServiceCollection::SORT_ORDER_ASC);
        $this->serviceCollection->loadData();
    }

    public function testGetSearchCriteriaOneField()
    {
        $field = 'age';
        $conditionType = 'gt';
        $value = '35';
        $sortOrder = $this->sortOrderBuilder
            ->setField('name')
            ->setDirection(SortOrder::SORT_ASC)
            ->create();
        /** @var SearchCriteria $expectedSearchCriteria */
        $filter = $this->filterBuilder->setField($field)->setConditionType($conditionType)->setValue($value)->create();
        $expectedSearchCriteria = $this->searchCriteriaBuilder
            ->setCurrentPage(1)
            ->setPageSize(0)
            ->addSortOrder($sortOrder)
            ->addFilters([$filter])
            ->create();

        // Verifies that the search criteria Data Object created by the serviceCollection matches expected
        $this->groupRepositoryMock->expects($this->once())
            ->method('getList')
            ->with($this->equalTo($expectedSearchCriteria))
            ->willReturn($this->searchResults);

        // Now call service collection to load the data.  This causes it to create the search criteria Data Object
        $this->serviceCollection->addFieldToFilter($field, [$conditionType => $value]);
        $this->serviceCollection->setOrder('name', ServiceCollection::SORT_ORDER_ASC);
        $this->serviceCollection->loadData();
    }

    public function testGetSearchCriteriaOr()
    {
        // Test ((A == 1) or (B == 1 ))
        $fieldA = 'A';
        $fieldB = 'B';
        $value = 1;

        $sortOrder = $this->sortOrderBuilder
            ->setField('name')
            ->setDirection(SortOrder::SORT_ASC)
            ->create();
        /** @var SearchCriteria $expectedSearchCriteria */
        $expectedSearchCriteria = $this->searchCriteriaBuilder
            ->setCurrentPage(1)
            ->setPageSize(0)
            ->addSortOrder($sortOrder)
            ->addFilters(
                [
                    $this->filterBuilder->setField($fieldA)->setConditionType('eq')->setValue($value)->create(),
                    $this->filterBuilder->setField($fieldB)->setConditionType('eq')->setValue($value)->create(),
                ]
            )
            ->create();

        // Verifies that the search criteria Data Object created by the serviceCollection matches expected
        $this->groupRepositoryMock->expects($this->once())
            ->method('getList')
            ->with($this->equalTo($expectedSearchCriteria))
            ->willReturn($this->searchResults);

        // Now call service collection to load the data.  This causes it to create the search criteria Data Object
        $this->serviceCollection->addFieldToFilter([$fieldA, $fieldB], [$value, $value]);
        $this->serviceCollection->setOrder('name', ServiceCollection::SORT_ORDER_ASC);
        $this->serviceCollection->loadData();
    }

    public function testGetSearchCriteriaAnd()
    {
        // Test ((A > 1) and (B > 1))
        $fieldA = 'A';
        $fieldB = 'B';
        $value = 1;

        $sortOrder = $this->sortOrderBuilder
            ->setField('name')
            ->setDirection(SortOrder::SORT_ASC)
            ->create();
        /** @var SearchCriteria $expectedSearchCriteria */
        $expectedSearchCriteria = $this->searchCriteriaBuilder
            ->setCurrentPage(1)
            ->setPageSize(0)
            ->addSortOrder($sortOrder)
            ->addFilters(
                [
                    $this->filterBuilder->setField($fieldA)->setConditionType('gt')
                        ->setValue($value)->create(),
                ]
            )
            ->addFilters(
                [
                    $this->filterBuilder->setField($fieldB)->setConditionType('gt')
                        ->setValue($value)->create(),
                ]
            )
            ->create();

        // Verifies that the search criteria Data Object created by the serviceCollection matches expected
        $this->groupRepositoryMock->expects($this->once())
            ->method('getList')
            ->with($this->equalTo($expectedSearchCriteria))
            ->willReturn($this->searchResults);

        // Now call service collection to load the data.  This causes it to create the search criteria Data Object
        $this->serviceCollection->addFieldToFilter($fieldA, ['gt' => $value]);
        $this->serviceCollection->addFieldToFilter($fieldB, ['gt' => $value]);
        $this->serviceCollection->setOrder('name', ServiceCollection::SORT_ORDER_ASC);
        $this->serviceCollection->loadData();
    }

    /**
     * @param string[] $fields
     * @param array $conditions
     *
     * @dataProvider addFieldToFilterInconsistentArraysDataProvider
     */
    public function testAddFieldToFilterInconsistentArrays($fields, $conditions)
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('The field array failed to pass. The array must have a matching condition array.');

        $this->serviceCollection->addFieldToFilter($fields, $conditions);
    }

    /**
     * @return array
     */
    public function addFieldToFilterInconsistentArraysDataProvider()
    {
        return [
            'missingCondition' => [
                ['fieldA', 'missingCondition'],
                [['eq' => 'A']],
            ],
            'missingField' => [
                ['fieldA'],
                [['eq' => 'A'], ['eq' => 'B']],
            ],
        ];
    }

    /**
     * @dataProvider addFieldToFilterInconsistentArraysDataProvider
     */
    public function testAddFieldToFilterEmptyArrays()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('The array of fields failed to pass. The array must include at one field.');

        $this->serviceCollection->addFieldToFilter([], []);
    }
}
