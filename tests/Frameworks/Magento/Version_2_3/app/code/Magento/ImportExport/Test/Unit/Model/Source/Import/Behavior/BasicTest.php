<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Test class for \Magento\ImportExport\Model\Source\Import\Behavior\Basic
 */
namespace Magento\ImportExport\Test\Unit\Model\Source\Import\Behavior;

class BasicTest extends \Magento\ImportExport\Test\Unit\Model\Source\Import\AbstractBehaviorTestCase
{
    /**
     * Expected behavior group code
     *
     * @var string
     */
    protected $_expectedCode = 'basic';

    /**
     * Expected behaviours
     *
     * @var array
     */
    protected $_expectedBehaviors = [
        \Magento\ImportExport\Model\Import::BEHAVIOR_APPEND,
        \Magento\ImportExport\Model\Import::BEHAVIOR_REPLACE,
        \Magento\ImportExport\Model\Import::BEHAVIOR_DELETE,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->_model = new \Magento\ImportExport\Model\Source\Import\Behavior\Basic();
    }

    /**
     * Test toArray method
     *
     * @covers \Magento\ImportExport\Model\Source\Import\Behavior\Basic::toArray
     */
    public function testToArray()
    {
        $behaviorData = $this->_model->toArray();
        $this->assertIsArray($behaviorData);
        $this->assertEquals($this->_expectedBehaviors, array_keys($behaviorData));
    }

    /**
     * Test behavior group code
     *
     * @covers \Magento\ImportExport\Model\Source\Import\Behavior\Basic::getCode
     */
    public function testGetCode()
    {
        $this->assertEquals($this->_expectedCode, $this->_model->getCode());
    }
}
