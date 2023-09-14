<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Elasticsearch\Test\Unit\SearchAdapter\Filter\Builder;

use Magento\Elasticsearch\Model\Adapter\FieldMapperInterface;
use Magento\Elasticsearch\SearchAdapter\Filter\Builder\Wildcard;
use Magento\Elasticsearch\SearchAdapter\Filter\Builder\Wildcard as WildcardBuilder;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @see \Magento\Elasticsearch\SearchAdapter\Filter\Builder\Wildcard
 */
class WildcardTest extends TestCase
{
    /**
     * @var Wildcard
     */
    private $model;

    /**
     * @var FieldMapperInterface|MockObject
     */
    protected $fieldMapper;

    /**
     * @var \Magento\Framework\Search\Request\Filter\Wildcard|MockObject
     */
    protected $filterInterface;

    /**
     * Set up test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->fieldMapper = $this->getMockBuilder(FieldMapperInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->filterInterface = $this->getMockBuilder(\Magento\Framework\Search\Request\Filter\Wildcard::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getField',
                'getValue',
            ])
            ->getMock();

        $objectManagerHelper = new ObjectManagerHelper($this);
        $this->model = $objectManagerHelper->getObject(
            WildcardBuilder::class,
            [
                'fieldMapper' => $this->fieldMapper
            ]
        );
    }

    public function testBuildFilter()
    {
        $this->fieldMapper->expects($this->any())
            ->method('getFieldName')
            ->willReturn('field');

        $this->filterInterface->expects($this->any())
            ->method('getField')
            ->willReturn('field');

        $result = $this->model->buildFilter($this->filterInterface);
        $this->assertNotNull($result);
    }
}
