<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Downloadable\Test\Unit\Model\Product\CopyConstructor;

class DownloadableTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Downloadable\Model\Product\CopyConstructor\Downloadable
     */
    protected $_model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_productMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_duplicateMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_linkMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_sampleMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_linkCollectionMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $jsonHelperMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_productTypeMock;

    protected function setUp(): void
    {
        $this->jsonHelperMock = $this->createMock(\Magento\Framework\Json\Helper\Data::class);
        $this->_model = new \Magento\Downloadable\Model\Product\CopyConstructor\Downloadable($this->jsonHelperMock);

        $this->_productMock = $this->createMock(\Magento\Catalog\Model\Product::class);

        $this->_duplicateMock = $this->createPartialMock(
            \Magento\Catalog\Model\Product::class,
            ['setDownloadableData', '__wakeup']
        );

        $this->_linkMock = $this->createMock(\Magento\Downloadable\Model\Link::class);

        $this->_sampleMock = $this->createMock(\Magento\Downloadable\Model\Sample::class);

        $this->_productTypeMock = $this->createMock(\Magento\Downloadable\Model\Product\Type::class);

        $this->jsonHelperMock->expects($this->any())->method('jsonEncode')->willReturnArgument(0);
    }

    public function testBuildWithNonDownloadableProductType()
    {
        $this->_productMock->expects($this->once())->method('getTypeId')->willReturn('some value');

        $this->_duplicateMock->expects($this->never())->method('setDownloadableData');

        $this->_model->build($this->_productMock, $this->_duplicateMock);
    }

    public function testBuild()
    {
        $expectedData = include __DIR__ . '/_files/expected_data.php';

        $this->_productMock->expects(
            $this->once()
        )->method(
            'getTypeId'
        )->willReturn(
            \Magento\Downloadable\Model\Product\Type::TYPE_DOWNLOADABLE
        );

        $this->_productMock->expects(
            $this->once()
        )->method(
            'getTypeInstance'
        )->willReturn(
            $this->_productTypeMock
        );

        $this->_productTypeMock->expects(
            $this->once()
        )->method(
            'getLinks'
        )->with(
            $this->_productMock
        )->willReturn(
            [$this->_linkMock]
        );

        $this->_productTypeMock->expects(
            $this->once()
        )->method(
            'getSamples'
        )->with(
            $this->_productMock
        )->willReturn(
            [$this->_sampleMock]
        );

        $linkData = [
            'title' => 'title',
            'is_shareable' => 'is_shareable',
            'sample_type' => 'sample_type',
            'sample_url' => 'sample_url',
            'sample_file' => 'sample_file',
            'link_file' => 'link_file',
            'link_type' => 'link_type',
            'link_url' => 'link_url',
            'sort_order' => 'sort_order',
            'price' => 'price',
            'number_of_downloads' => 'number_of_downloads',
        ];

        $sampleData = [
            'title' => 'title',
            'sample_type' => 'sample_type',
            'sample_file' => 'sample_file',
            'sample_url' => 'sample_url',
            'sort_order' => 'sort_order',
        ];

        $this->_linkMock->expects($this->once())->method('getData')->willReturn($linkData);
        $this->_sampleMock->expects($this->once())->method('getData')->willReturn($sampleData);

        $this->_duplicateMock->expects($this->once())->method('setDownloadableData')->with($expectedData);
        $this->_model->build($this->_productMock, $this->_duplicateMock);
    }
}
