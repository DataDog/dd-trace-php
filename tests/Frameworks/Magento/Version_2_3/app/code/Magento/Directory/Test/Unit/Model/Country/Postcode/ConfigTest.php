<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Directory\Test\Unit\Model\Country\Postcode;

class ConfigTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $dataStorageMock;

    protected function setUp(): void
    {
        $this->dataStorageMock = $this->createMock(\Magento\Directory\Model\Country\Postcode\Config\Data::class);
    }

    public function testGet()
    {
        $expected = ['US' => ['pattern_01' => 'pattern_01', 'pattern_02' => 'pattern_02']];
        $this->dataStorageMock->expects($this->once())->method('get')->willReturn($expected);
        $configData = new \Magento\Directory\Model\Country\Postcode\Config($this->dataStorageMock);
        $this->assertEquals($expected, $configData->getPostCodes());
    }
}
