<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Api\Test\Unit\SearchCriteria\CollectionProcessor\ConditionProcessor;

use Magento\Framework\Api\SearchCriteria\CollectionProcessor\ConditionProcessor\CustomConditionProvider;
use Magento\Framework\Api\SearchCriteria\CollectionProcessor\ConditionProcessor\CustomConditionInterface;

class CustomConditionProviderTest extends \PHPUnit\Framework\TestCase
{
    private $customConditionProcessorBuilder;
    private $customConditionMock;

    protected function setUp(): void
    {
        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->customConditionMock = $this->getMockBuilder(CustomConditionInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->customConditionProcessorBuilder = $objectManagerHelper
            ->getObject(
                CustomConditionProvider::class,
                [
                    'customConditionProcessors' => [
                        'my-valid-field' => $this->customConditionMock,
                    ]
                ]
            );
    }

    public function testPositiveHasProcessorForField()
    {
        $testField = 'my-valid-field';

        $this->assertTrue(
            $this->customConditionProcessorBuilder->hasProcessorForField($testField)
        );
    }

    public function testNegativeHasProcessorForField()
    {
        $testField = 'unknown-field';

        $this->assertFalse(
            $this->customConditionProcessorBuilder->hasProcessorForField($testField)
        );
    }

    public function testPositiveGetProcessorByField()
    {
        $testField = 'my-valid-field';

        $this->assertEquals(
            $this->customConditionMock,
            $this->customConditionProcessorBuilder->getProcessorByField($testField)
        );
    }

    /**
     */
    public function testNegativeGetProcessorByFieldExceptionFieldIsAbsent()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);
        $this->expectExceptionMessage('Custom processor for field "unknown-field" is absent.');

        $testField = 'unknown-field';
        $this->customConditionProcessorBuilder->getProcessorByField($testField);
    }

    /**
     */
    public function testNegativeGetProcessorByFieldExceptionWrongClass()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);
        $this->expectExceptionMessage('Custom processor must implement "Magento\\Framework\\Api\\SearchCriteria\\CollectionProcessor\\ConditionProcessor\\CustomConditionInterface".');

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->customConditionProcessorBuilder = $objectManagerHelper
            ->getObject(
                CustomConditionProvider::class,
                [
                    'customConditionProcessors' => [
                        'my-valid-field' => $this->customConditionMock,
                        'my-invalid-field' => 'olo-lo'
                    ]
                ]
            );
    }
}
