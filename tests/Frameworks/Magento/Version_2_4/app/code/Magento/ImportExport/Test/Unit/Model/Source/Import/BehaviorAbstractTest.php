<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

/**
 * Test class for \Magento\ImportExport\Model\Source\Import\AbstractBehavior
 */
namespace Magento\ImportExport\Test\Unit\Model\Source\Import;

use Magento\ImportExport\Model\Source\Import\AbstractBehavior;

class BehaviorAbstractTest extends AbstractBehaviorTestCase
{
    /**
     * Source array data
     *
     * @var array
     */
    protected $_sourceArray = ['key_1' => 'label_1', 'key_2' => 'label_2'];

    /**
     * Expected options (without first empty record)
     *
     * @var array
     */
    protected $_expectedOptions = [
        ['value' => 'key_1', 'label' => 'label_1'],
        ['value' => 'key_2', 'label' => 'label_2'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $model = $this->getMockForAbstractClass(
            AbstractBehavior::class,
            [[]],
            '',
            false,
            true,
            true,
            ['toArray']
        );
        $model->expects($this->any())->method('toArray')->willReturn($this->_sourceArray);

        $this->_model = $model;
    }

    /**
     * Test for toOptionArray method
     *
     * @covers \Magento\ImportExport\Model\Source\Import\AbstractBehavior::toOptionArray
     */
    public function testToOptionArray()
    {
        $actualOptions = $this->_model->toOptionArray();

        // all elements must have value and label fields
        foreach ($actualOptions as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
        }

        // first element must has empty value
        $firstElement = $actualOptions[0];
        $this->assertEquals('', $firstElement['value']);

        // other elements must be equal to expected data
        $actualOptions = array_slice($actualOptions, 1);
        $this->assertEquals($this->_expectedOptions, $actualOptions);
    }
}
