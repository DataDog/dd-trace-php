<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\DataObject\Test\Unit;

class MapperTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\DataObject\Mapper
     */
    protected $mapper;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $fromMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $toMock;

    protected function setUp(): void
    {
        $this->fromMock = $this->createMock(\Magento\Framework\DataObject::class);
        $this->toMock = $this->createMock(\Magento\Framework\DataObject::class);
        $this->mapper = new \Magento\Framework\DataObject\Mapper();
    }

    public function testAccumulateByMapWhenToIsArrayFromIsObject()
    {
        $map['key'] = 'map_value';
        $to['key'] = 'from_value';
        $default['new_key'] = 'default_value';
        $this->fromMock->expects($this->once())->method('hasData')->with('key')->willReturn(true);
        $this->fromMock->expects($this->once())->method('getData')->with('key')->willReturn('from_value');
        $expected['key'] = 'from_value';
        $expected['map_value'] = 'from_value';
        $expected['new_key'] = 'default_value';
        $this->assertEquals($expected, $this->mapper->accumulateByMap($this->fromMock, $to, $map, $default));
    }

    public function testAccumulateByMapWhenToAndFromAreObjects()
    {
        $from = [
            $this->fromMock,
            'getData',
        ];
        $to = [
            $this->toMock,
            'setData',
        ];
        $default = [0];
        $map['key'] = ['value'];
        $this->fromMock->expects($this->once())->method('hasData')->with('key')->willReturn(false);
        $this->fromMock->expects($this->once())->method('getData')->with('key')->willReturn(true);
        $this->assertEquals($this->toMock, $this->mapper->accumulateByMap($from, $to, $map, $default));
    }

    public function testAccumulateByMapWhenFromIsArrayToIsObject()
    {
        $map['key'] = 'map_value';
        $from['key'] = 'from_value';
        $default['new_key'] = 'default_value';
        $this->toMock->expects($this->exactly(2))->method('setData');
        $this->assertEquals($this->toMock, $this->mapper->accumulateByMap($from, $this->toMock, $map, $default));
    }

    public function testAccumulateByMapFromAndToAreArrays()
    {
        $from['value'] = 'from_value';
        $map[false] = 'value';
        $to['key'] = 'to_value';
        $default['new_key'] = 'value';
        $expected['key'] = 'to_value';
        $expected['value'] = 'from_value';
        $expected['new_key'] = 'value';
        $this->assertEquals($expected, $this->mapper->accumulateByMap($from, $to, $map, $default));
    }
}
