<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Setup\Test\Unit\Module\Di\Code\Scanner;

require_once __DIR__ . '/../../_files/app/code/Magento/SomeModule/Helper/Test.php';
require_once __DIR__ . '/../../_files/app/code/Magento/SomeModule/ElementFactory.php';
require_once __DIR__ . '/../../_files/app/code/Magento/SomeModule/Model/DoubleColon.php';
require_once __DIR__ . '/../../_files/app/code/Magento/SomeModule/Api/Data/SomeInterface.php';

use Magento\Framework\Reflection\TypeProcessor;

class PhpScannerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Setup\Module\Di\Code\Scanner\PhpScanner
     */
    protected $_model;

    /**
     * @var string
     */
    protected $_testDir;

    /**
     * @var array
     */
    protected $_testFiles = [];

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_logMock;

    protected function setUp(): void
    {
        $this->_logMock = $this->createMock(\Magento\Setup\Module\Di\Compiler\Log\Log::class);
        $this->_model = new \Magento\Setup\Module\Di\Code\Scanner\PhpScanner($this->_logMock, new TypeProcessor());
        $this->_testDir = str_replace('\\', '/', realpath(__DIR__ . '/../../') . '/_files');
    }

    public function testCollectEntities()
    {
        $this->_testFiles = [
            $this->_testDir . '/app/code/Magento/SomeModule/Helper/Test.php',
            $this->_testDir . '/app/code/Magento/SomeModule/Model/DoubleColon.php',
            $this->_testDir . '/app/code/Magento/SomeModule/Api/Data/SomeInterface.php'
        ];

        $this->_logMock->expects(
            $this->at(0)
        )->method(
            'add'
        )->with(
            4,
            'Magento\SomeModule\Module\Factory',
            'Invalid Factory for nonexistent class Magento\SomeModule\Module in file ' . $this->_testFiles[0]
        );
        $this->_logMock->expects(
            $this->at(1)
        )->method(
            'add'
        )->with(
            4,
            'Magento\SomeModule\Element\Factory',
            'Invalid Factory declaration for class Magento\SomeModule\Element in file ' . $this->_testFiles[0]
        );

        $this->assertEquals(
            ['\\' . \Magento\Eav\Api\Data\AttributeExtensionInterface::class],
            $this->_model->collectEntities($this->_testFiles)
        );
    }
}
