<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Test class for \Magento\Backend\Block\Widget\Grid\Massaction\Extended
 */
namespace Magento\Backend\Test\Unit\Block\Widget\Grid\Massaction;

class ExtendedTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Backend\Block\Widget\Grid\Massaction
     */
    protected $_block;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_layoutMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_gridMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_eventManagerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_urlModelMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_requestMock;

    protected function setUp(): void
    {
        $this->_gridMock = $this->createPartialMock(
            \Magento\Backend\Block\Widget\Grid::class,
            ['getId', 'getCollection']
        );
        $this->_gridMock->expects($this->any())->method('getId')->willReturn('test_grid');

        $this->_layoutMock = $this->createPartialMock(
            \Magento\Framework\View\Layout::class,
            ['getParentName', 'getBlock', 'helper']
        );

        $this->_layoutMock->expects(
            $this->any()
        )->method(
            'getParentName'
        )->with(
            'test_grid_massaction'
        )->willReturn(
            'test_grid'
        );
        $this->_layoutMock->expects(
            $this->any()
        )->method(
            'getBlock'
        )->with(
            'test_grid'
        )->willReturn(
            $this->_gridMock
        );

        $this->_requestMock = $this->createMock(\Magento\Framework\App\Request\Http::class);

        $this->_urlModelMock = $this->createMock(\Magento\Backend\Model\Url::class);

        $arguments = [
            'layout' => $this->_layoutMock,
            'request' => $this->_requestMock,
            'urlBuilder' => $this->_urlModelMock,
            'data' => ['massaction_id_field' => 'test_id', 'massaction_id_filter' => 'test_id'],
        ];

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->_block = $objectManagerHelper->getObject(
            \Magento\Backend\Block\Widget\Grid\Massaction\Extended::class,
            $arguments
        );
        $this->_block->setNameInLayout('test_grid_massaction');
    }

    protected function tearDown(): void
    {
        unset($this->_layoutMock);
        unset($this->_eventManagerMock);
        unset($this->_gridMock);
        unset($this->_urlModelMock);
        unset($this->_block);
    }

    public function testUseSelectAll()
    {
        $this->_block->setUseSelectAll(false);
        $this->assertFalse($this->_block->getUseSelectAll());

        $this->_block->setUseSelectAll(true);
        $this->assertTrue($this->_block->getUseSelectAll());
    }

    public function testGetGridIdsJsonWithoutUseSelectAll()
    {
        $this->_block->setUseSelectAll(false);
        $this->assertEmpty($this->_block->getGridIdsJson());
    }

    /**
     * @param array $items
     * @param string $result
     *
     * @dataProvider dataProviderGetGridIdsJsonWithUseSelectAll
     */
    public function testGetGridIdsJsonWithUseSelectAll(array $items, $result)
    {
        $this->_block->setUseSelectAll(true);
        
        if ($this->_block->getMassactionIdField()) {
            $massActionIdField = $this->_block->getMassactionIdField();
        } else {
            $massActionIdField = $this->_block->getParentBlock()->getMassactionIdField();
        }
        
        $collectionMock = $this->getMockBuilder(\Magento\Framework\Data\Collection::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->_gridMock->expects($this->once())
            ->method('getCollection')
            ->willReturn($collectionMock);

        $collectionMock->expects($this->once())
            ->method('setPageSize')
            ->with(0)
            ->willReturnSelf();
        $collectionMock->expects($this->once())
            ->method('getColumnValues')
            ->with($massActionIdField)
            ->willReturn($items);

        $this->assertEquals($result, $this->_block->getGridIdsJson());
    }

    /**
     * @return array
     */
    public function dataProviderGetGridIdsJsonWithUseSelectAll()
    {
        return [
            [
                [],
                '',
            ],
            [
                [1],
                '1',
            ],
            [
                [1, 2, 3],
                '1,2,3',
            ],
        ];
    }
}
