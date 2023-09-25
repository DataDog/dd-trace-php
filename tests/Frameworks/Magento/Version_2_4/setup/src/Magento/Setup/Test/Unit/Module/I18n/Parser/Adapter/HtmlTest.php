<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Setup\Test\Unit\Module\I18n\Parser\Adapter;

use Magento\Setup\Module\I18n\Parser\Adapter\Html;
use PHPUnit\Framework\TestCase;

class HtmlTest extends TestCase
{
    /**
     * @var Html
     */
    private $model;

    /**
     * @var string
     */
    private $testFile;

    protected function setUp(): void
    {
        $this->testFile = str_replace('\\', '/', realpath(__DIR__)) . '/_files/email.html';
        $this->model = new Html();
    }

    public function testParse()
    {
        $expectedResult = [
            [
                'phrase' => 'Phrase 1',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '\'',
            ],
            [
                'phrase' => 'Phrase 2 with %a_lot of extra info for the brilliant %customer_name.',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '"',
            ],
            [
                'phrase' => 'This is test data',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ],
            // `I18N` is not parsed - bindings are case-sensitive
            [
                'phrase' => 'This is test data at right side of attr',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                'phrase' => 'This is \\\' test \\\' data',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                'phrase' => 'This is \\" test \\" data',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                'phrase' => 'This is test data with a quote after',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                'phrase' => 'This is test data with space after ',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                'phrase' => '\\\'',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                'phrase' => '\\\\\\\\ ',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                'phrase' => 'This is test content in translate tag',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ],
            // `<TRANSLATE>` tag is not parsed - only lowercase tags are accepted
            [
                'phrase' => 'This is test content in translate attribute',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ],
            // `TRANSLATE` attribute is not parsed - only lowercase attribute names are accepted
            // `$T()` is not parsed - function names in JS are case-sensitive
            [
                // en_US.csv: "This is ' test ' data for attribute translation with single quotes","This is ' test ' data for attribute translation with single quotes"
                'phrase' => 'This is \\\' test \\\' data for attribute translation with single quotes',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                // en_US.csv: "This is test data for attribute translation with a quote after''","This is test data for attribute translation with a quote after''"
                'phrase' => 'This is test data for attribute translation with a quote after\\\'\\\'',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                // en_US.csv: "This is test data for attribute translation with a quote after' ' ","This is test data for attribute translation with a quote after' ' "
                'phrase' => 'This is test data for attribute translation with a quote after\\\' \\\' ',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                // en_US.csv: "Attribute translation - Placeholder","Attribute translation - Placeholder"
                'phrase' => 'Attribute translation - Placeholder',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ],
            [
                // en_US.csv: "Attribute translation - Title","Attribute translation - Title"
                'phrase' => 'Attribute translation - Title',
                'file' => $this->testFile,
                'line' => '',
                'quote' => '',
            ]
        ];

        $this->model->parse($this->testFile);

        $this->assertEquals($expectedResult, $this->model->getPhrases());
    }
}
