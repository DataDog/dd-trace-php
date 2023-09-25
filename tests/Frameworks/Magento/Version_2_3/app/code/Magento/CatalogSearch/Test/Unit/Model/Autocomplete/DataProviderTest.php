<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogSearch\Test\Unit\Model\Autocomplete;

use Magento\CatalogSearch\Model\Autocomplete\DataProvider;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class DataProviderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var DataProvider
     */
    private $model;

    /**
     * @var \Magento\Search\Model\Query |\PHPUnit\Framework\MockObject\MockObject
     */
    private $query;

    /**
     * @var \Magento\Search\Model\Autocomplete\ItemFactory |\PHPUnit\Framework\MockObject\MockObject
     */
    private $itemFactory;

    /**
     * @var \Magento\Search\Model\ResourceModel\Query\Collection |\PHPUnit\Framework\MockObject\MockObject
     */
    private $suggestCollection;

    /**
     * @var integer
     */
    private $limit = 3;

    protected function setUp(): void
    {
        $helper = new ObjectManager($this);

        $this->suggestCollection = $this->getMockBuilder(\Magento\Search\Model\ResourceModel\Query\Collection::class)
            ->disableOriginalConstructor()
            ->setMethods(['getIterator'])
            ->getMock();

        $this->query = $this->getMockBuilder(\Magento\Search\Model\Query::class)
            ->disableOriginalConstructor()
            ->setMethods(['getQueryText', 'getSuggestCollection'])
            ->getMock();
        $this->query->expects($this->any())
            ->method('getSuggestCollection')
            ->willReturn($this->suggestCollection);

        $queryFactory = $this->getMockBuilder(\Magento\Search\Model\QueryFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['get'])
            ->getMock();
        $queryFactory->expects($this->any())
            ->method('get')
            ->willReturn($this->query);

        $this->itemFactory = $this->getMockBuilder(\Magento\Search\Model\Autocomplete\ItemFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $scopeConfig = $this->getMockBuilder(\Magento\Framework\App\Config\ScopeConfigInterface::class)
            ->setMethods(['getValue'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $scopeConfig->expects($this->any())
            ->method('getValue')
            ->willReturn($this->limit);

        $this->model = $helper->getObject(
            \Magento\CatalogSearch\Model\Autocomplete\DataProvider::class,
            [
                'queryFactory' => $queryFactory,
                'itemFactory' => $this->itemFactory,
                'scopeConfig' => $scopeConfig
            ]
        );
    }

    public function testGetItems()
    {
        $queryString = 'string';
        $expected = ['title' => $queryString, 'num_results' => 100500];
        $collection = [
            ['query_text' => 'string1', 'num_results' => 1],
            ['query_text' => 'string2', 'num_results' => 2],
            ['query_text' => 'string11', 'num_results' => 11],
            ['query_text' => 'string100', 'num_results' => 100],
            ['query_text' => $queryString, 'num_results' => 100500]
        ];
        $this->buildCollection($collection);
        $this->query->expects($this->once())
            ->method('getQueryText')
            ->willReturn($queryString);

         $itemMock =  $this->getMockBuilder(\Magento\Search\Model\Autocomplete\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(['getTitle', 'toArray'])
            ->getMock();
        $itemMock->expects($this->any())
            ->method('getTitle')
            ->will($this->onConsecutiveCalls(
                $queryString,
                'string1',
                'string2',
                'string11',
                'string100'
            ));
        $itemMock->expects($this->any())
            ->method('toArray')
            ->willReturn($expected);

        $this->itemFactory->expects($this->any())->method('create')->willReturn($itemMock);

        $result = $this->model->getItems();
        $this->assertEquals($expected, $result[0]->toArray());
        $this->assertEquals($this->limit, count($result));
    }

    /**
     * @param array $data
     */
    private function buildCollection(array $data)
    {
        $collectionData = [];
        foreach ($data as $collectionItem) {
            $collectionData[] = new \Magento\Framework\DataObject($collectionItem);
        }
        $this->suggestCollection->expects($this->any())
            ->method('getIterator')
            ->willReturn(new \ArrayIterator($collectionData));
    }
}
