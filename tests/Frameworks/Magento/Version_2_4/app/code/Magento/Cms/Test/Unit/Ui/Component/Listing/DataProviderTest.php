<?php
/***
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Cms\Test\Unit\Ui\Component\Listing;

use Magento\Cms\Ui\Component\DataProvider;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Authorization;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\Reporting;
use Magento\Ui\Component\Container;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DataProviderTest extends TestCase
{
    /**
     * @var Authorization|MockObject
     */
    private $authorizationMock;

    /**
     * @var Reporting|MockObject
     */
    private $reportingMock;

    /**
     * @var SearchCriteriaBuilder|MockObject
     */
    private $searchCriteriaBuilderMock;

    /**
     * @var RequestInterface|MockObject
     */
    private $requestInterfaceMock;

    /**
     * @var FilterBuilder|MockObject
     */
    private $filterBuilderMock;

    /**
     * @var DataProvider
     */
    private $dataProvider;

    /**
     * @var string
     */
    private $name = 'cms_page_listing_data_source';

    /**
     * @var string
     */
    private $primaryFieldName = 'page';

    /**
     * @var string
     */
    private $requestFieldName = 'id';

    protected function setUp(): void
    {
        $this->authorizationMock = $this->getMockBuilder(Authorization::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->reportingMock = $this->getMockBuilder(Reporting::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->searchCriteriaBuilderMock = $this->getMockBuilder(SearchCriteriaBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->requestInterfaceMock = $this->getMockBuilder(RequestInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->filterBuilderMock = $this->getMockBuilder(FilterBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var ObjectManagerInterface|MockObject $objectManagerMock */
        $objectManagerMock = $this->getMockForAbstractClass(ObjectManagerInterface::class);
        $objectManagerMock->expects($this->once())
            ->method('get')
            ->willReturn($this->authorizationMock);
        ObjectManager::setInstance($objectManagerMock);

        $this->dataProvider = new DataProvider(
            $this->name,
            $this->primaryFieldName,
            $this->requestFieldName,
            $this->reportingMock,
            $this->searchCriteriaBuilderMock,
            $this->requestInterfaceMock,
            $this->filterBuilderMock
        );
    }

    /**
     * @covers \Magento\Cms\Ui\Component\DataProvider::prepareMetadata
     */
    public function testPrepareMetadata()
    {
        $this->authorizationMock->expects($this->once())
            ->method('isAllowed')
            ->with('Magento_Cms::save')
            ->willReturn(false);

        $metadata = [
            'cms_page_columns' => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'editorConfig' => [
                                'enabled' => false
                            ],
                            'componentType' => Container::NAME
                        ]
                    ]
                ]
            ]
        ];

        $this->assertEquals(
            $metadata,
            $this->dataProvider->prepareMetadata()
        );
    }
}
