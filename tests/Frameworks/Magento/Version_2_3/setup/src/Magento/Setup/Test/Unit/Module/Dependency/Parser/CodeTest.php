<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Setup\Test\Unit\Module\Dependency\Parser;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class CodeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Setup\Module\Dependency\Parser\Code
     */
    protected $parser;

    protected function setUp(): void
    {
        $objectManagerHelper = new ObjectManager($this);
        $this->parser = $objectManagerHelper->getObject(\Magento\Setup\Module\Dependency\Parser\Code::class);
    }

    /**
     * @param array $options
     * @dataProvider dataProviderWrongOptionFilesForParse
     */
    public function testParseWithWrongOptionFilesForParse($options)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Parse error: Option "files_for_parse" is wrong.');

        $this->parser->parse($options);
    }

    /**
     * @return array
     */
    public function dataProviderWrongOptionFilesForParse()
    {
        return [
            [['files_for_parse' => [], 'declared_namespaces' => [1, 2]]],
            [['files_for_parse' => 'sting', 'declared_namespaces' => [1, 2]]],
            [['there_are_no_files_for_parse' => [1, 3], 'declared_namespaces' => [1, 2]]]
        ];
    }

    /**
     * @param array $options
     * @dataProvider dataProviderWrongOptionDeclaredNamespace
     */
    public function testParseWithWrongOptionDeclaredNamespace($options)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Parse error: Option "declared_namespaces" is wrong.');

        $this->parser->parse($options);
    }

    /**
     * @return array
     */
    public function dataProviderWrongOptionDeclaredNamespace()
    {
        return [
            [['declared_namespaces' => [], 'files_for_parse' => [1, 2]]],
            [['declared_namespaces' => 'sting', 'files_for_parse' => [1, 2]]],
            [['there_are_no_declared_namespaces' => [1, 3], 'files_for_parse' => [1, 2]]]
        ];
    }
}
