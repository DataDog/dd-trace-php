<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\WebapiAsync\Test\Unit\Model\ServiceConfig;

use Magento\WebapiAsync\Model\ServiceConfig\Converter;
use PHPUnit\Framework\TestCase;

class ConverterTest extends TestCase
{
    /**
     * @var Converter
     */
    private $model;

    protected function setUp(): void
    {
        $this->model = new Converter();
    }

    /**
     * @covers \Magento\WebapiAsync\Model\ServiceConfig\Converter::convert()
     */
    public function testConvert()
    {
        $inputData = new \DOMDocument();
        $inputData->load(__DIR__ . '/_files/Converter/webapi_async.xml');
        $expectedResult = require __DIR__ . '/_files/Converter/webapi_async.php';
        $this->assertEquals($expectedResult, $this->model->convert($inputData));
    }
}
