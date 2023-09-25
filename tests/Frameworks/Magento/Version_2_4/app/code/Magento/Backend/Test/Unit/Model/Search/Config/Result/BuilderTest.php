<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Backend\Test\Unit\Model\Search\Config\Result;

use Magento\Backend\Model\Search\Config\Result\Builder;
use Magento\Backend\Model\Search\Config\Structure\ElementBuilderInterface;
use Magento\Backend\Model\UrlInterface;
use Magento\Config\Model\Config\StructureElementInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class BuilderTest extends TestCase
{
    /**
     * @var Builder
     */
    protected $model;

    /**
     * @var StructureElementInterface|MockObject
     */
    protected $structureElementMock;

    /**
     * @var UrlInterface|MockObject
     */
    protected $urlBuilderMock;

    /**
     * @var ElementBuilderInterface|MockObject
     */
    protected $structureElementUrlParamsBuilderMock;

    protected function setUp(): void
    {
        $this->urlBuilderMock = $this->getMockForAbstractClass(UrlInterface::class);
        $this->structureElementMock = $this->getMockForAbstractClass(StructureElementInterface::class);
        $this->structureElementUrlParamsBuilderMock = $this->getMockForAbstractClass(ElementBuilderInterface::class);
        $this->model = new Builder($this->urlBuilderMock, ['section' => $this->structureElementUrlParamsBuilderMock]);
    }

    public function testAddWithNotSupportedStructureElementReturnsNothing()
    {
        $this->structureElementMock
            ->expects($this->once())
            ->method('getData')
            ->willReturn(['_elementType' => 'not_declared_structure_element_type']);
        $this->model->add($this->structureElementMock, '');
        $this->assertEquals([], $this->model->getAll());
    }

    public function testAddWithSupportedStructureElements()
    {
        $structureElementPath = '/section_code';
        $structureElementLabel = 'Section Label';
        $buildUrlParams = ['param_key' => 'param_value'];
        $generatedUrl = 'http://example.com';

        $expectedSearchResult = [
            [
                'id'          => $structureElementPath,
                'type'        => null,
                'name'        => 'Section Label',
                'description' => 'Section Label',
                'url'         => 'http://example.com',
            ],
        ];

        $this->structureElementMock
            ->expects($this->once())
            ->method('getData')
            ->willReturn(['_elementType' => 'section']);
        $this->structureElementMock
            ->expects($this->once())
            ->method('getPath')
            ->willReturn($structureElementPath);
        $this->structureElementMock
            ->expects($this->once())
            ->method('getLabel')
            ->willReturn($structureElementLabel);

        $this->structureElementUrlParamsBuilderMock->expects($this->once())
            ->method('build')
            ->willReturn($buildUrlParams);

        $this->urlBuilderMock
            ->expects($this->once())
            ->method('getUrl')
            ->with('*/system_config/edit', $buildUrlParams)
            ->willReturn($generatedUrl);

        $this->model->add($this->structureElementMock, $structureElementLabel);
        $this->assertEquals($expectedSearchResult, $this->model->getAll());
    }
}
