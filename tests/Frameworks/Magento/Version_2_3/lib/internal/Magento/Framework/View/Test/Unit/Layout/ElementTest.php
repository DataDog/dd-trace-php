<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Test class for \Magento\Framework\View\Layout\Element
 */
namespace Magento\Framework\View\Test\Unit\Layout;

class ElementTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @dataProvider elementNameDataProvider
     */
    public function testGetElementName($xml, $name)
    {
        $model = new \Magento\Framework\View\Layout\Element($xml);
        $this->assertEquals($name, $model->getElementName());
    }

    /**
     * @return array
     */
    public function elementNameDataProvider()
    {
        return [
            ['<block name="name" />', 'name'],
            ['<container name="name" />', 'name'],
            ['<referenceBlock name="name" />', 'name'],
            ['<invalid name="name" />', false],
            ['<block />', '']
        ];
    }

    /**
     * @return array
     */
    public function cacheableDataProvider()
    {
        return [
            ['<containter name="name" />', true],
            ['<block name="name" cacheable="false" />', false],
            ['<block name ="bl1"><block name="bl2" /></block>', true],
            ['<block name ="bl1"><block name="bl2" cacheable="false"/></block>', false],
            ['<block name="name" />', true],
            ['<renderer cacheable="false" />', true],
            ['<renderer name="name" />', true],
            ['<widget cacheable="false" />', true],
            ['<widget name="name" />', true]
        ];
    }

    /**
     * @dataProvider cacheableDataProvider
     */
    public function testIsCacheable($xml, $expected)
    {
        $model = new \Magento\Framework\View\Layout\Element($xml);
        $this->assertEquals($expected, $model->isCacheable());
    }
}
