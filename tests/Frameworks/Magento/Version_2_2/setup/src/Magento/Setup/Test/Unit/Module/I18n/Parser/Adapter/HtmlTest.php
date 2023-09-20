<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Setup\Test\Unit\Module\I18n\Parser\Adapter;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Setup\Module\I18n\Dictionary\Phrase;

class HtmlTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var string
     */
    protected $_testFile;

    /**
     * @var int
     */
    protected $_stringsCount;

    /**
     * @var \Magento\Setup\Module\I18n\Parser\Adapter\Html
     */
    protected $_adapter;

    protected function setUp()
    {
        $this->_testFile = str_replace('\\', '/', realpath(dirname(__FILE__))) . '/_files/email.html';
        $this->_stringsCount = count(file($this->_testFile));

        $this->_adapter = (new ObjectManager($this))->getObject(\Magento\Setup\Module\I18n\Parser\Adapter\Html::class);
    }

    public function testParse()
    {
        $expectedResult = [
            [
                'phrase' => 'Phrase 1',
                'file' => $this->_testFile,
                'line' => '',
                'quote' => '\'',
            ],
            [
                'phrase' => 'Phrase 2 with %a_lot of extra info for the brilliant %customer_name.',
                'file' => $this->_testFile,
                'line' => '',
                'quote' => '"',
            ],
            [
                'phrase' => 'This is test data',
                'file' => $this->_testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                'phrase' => 'This is test data at right side of attr',
                'file' => $this->_testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                'phrase' => 'This is \\\' test \\\' data',
                'file' => $this->_testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                'phrase' => 'This is \\" test \\" data',
                'file' => $this->_testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                'phrase' => 'This is test data with a quote after',
                'file' => $this->_testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                'phrase' => 'This is test data with space after ',
                'file' => $this->_testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                'phrase' => '\\\'',
                'file' => $this->_testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                'phrase' => '\\\\\\\\ ',
                'file' => $this->_testFile,
                'line' => '',
                'quote' => '',
            ],
        ];

        $this->_adapter->parse($this->_testFile);

        $this->assertEquals($expectedResult, $this->_adapter->getPhrases());
    }
}
