<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Indexer\Test\Unit\Config;

use Magento\Framework\Exception\ConfigurationMismatchException;

class ConverterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Indexer\Config\Converter|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_model = new \Magento\Framework\Indexer\Config\Converter();
    }

    public function testConvert()
    {
        $data = include __DIR__ . '/../_files/indexer_config.php';
        $dom = new \DOMDocument();
        $dom->loadXML($data['inputXML']);

        $this->assertEquals($data['expected'], $this->_model->convert($dom));
    }

    /**
     * @param string $xml
     * @param array $indexersSequence
     * @dataProvider convertWithDependenciesDataProvider
     */
    public function testConvertWithDependencies(string $xml, array $indexersSequence)
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        $this->assertEquals($indexersSequence, array_keys($this->_model->convert($dom)));
    }

    /**
     * @return array
     */
    public function convertWithDependenciesDataProvider()
    {
        return [
            [
                'xml' =>
                    <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<config>
    <indexer id="indexer_1" view_id="view_one" class="Index\Class\Name\One">
        <dependencies>
            <indexer id="indexer_6" />
        </dependencies>
    </indexer>
    <indexer id="indexer_2" view_id="view_two" class="Index\Class\Name\Two">
        <dependencies>
            <indexer id="indexer_3" />
        </dependencies>
    </indexer>
    <indexer id="indexer_3" view_id="view_three" class="Index\Class\Name\Three">
    </indexer>
    <indexer id="indexer_4" view_id="view_four" class="Index\Class\Name\Four">
        <dependencies>
            <indexer id="indexer_6" />
            <indexer id="indexer_5" />
        </dependencies>
    </indexer>
    <indexer id="indexer_5" view_id="view_five" class="Index\Class\Name\Five">
        <dependencies>
            <indexer id="indexer_1" />
        </dependencies>
    </indexer>
    <indexer id="indexer_6" view_id="view_six" class="Index\Class\Name\Six">
        <dependencies>
            <indexer id="indexer_2" />
        </dependencies>
    </indexer>
</config>
XML
                ,
                'indexersSequence' => [
                    'indexer_3',
                    'indexer_2',
                    'indexer_6',
                    'indexer_1',
                    'indexer_5',
                    'indexer_4',
                ],
            ]
        ];
    }

    /**
     * @param string $inputXml
     * @param string $exceptionMessage
     * @dataProvider convertWithCircularDependenciesDataProvider
     */
    public function testConvertWithCircularDependencies($inputXml, $exceptionMessage)
    {
        $dom = new \DOMDocument();
        $dom->loadXML($inputXml);
        $this->expectException(ConfigurationMismatchException::class);
        $this->expectExceptionMessage($exceptionMessage);
        $this->_model->convert($dom);
    }

    /**
     * @return array
     */
    public function convertWithCircularDependenciesDataProvider()
    {
        return [
            'Circular dependency on the first level' => [
                'inputXML' => '<?xml version="1.0" encoding="UTF-8"?><config>'
                    . '<indexer id="indexer_1"><dependencies><indexer id="indexer_2"/></dependencies></indexer>'
                    . '<indexer id="indexer_2"><dependencies><indexer id="indexer_1"/></dependencies></indexer>'
                    . '</config>',
                'exceptionMessage' => "Circular dependency references from 'indexer_2' to 'indexer_1'.",
            ],
            'Circular dependency a deeper than the first level' => [
                'inputXML' => '<?xml version="1.0" encoding="UTF-8"?><config>'
                    . '<indexer id="indexer_1"><dependencies><indexer id="indexer_2"/></dependencies></indexer>'
                    . '<indexer id="indexer_2"><dependencies><indexer id="indexer_3"/></dependencies></indexer>'
                    . '<indexer id="indexer_3"><dependencies><indexer id="indexer_4"/></dependencies></indexer>'
                    . '<indexer id="indexer_4"><dependencies><indexer id="indexer_1"/></dependencies></indexer>'
                    . '</config>',
                'exceptionMessage' => "Circular dependency references from 'indexer_4' to 'indexer_1'.",
            ],
        ];
    }

    /**
     * @param string $inputXml
     * @param string $exceptionMessage
     * @dataProvider convertWithDependencyOnNotExistingIndexerDataProvider
     */
    public function testConvertWithDependencyOnNotExistingIndexer($inputXml, $exceptionMessage)
    {
        $dom = new \DOMDocument();
        $dom->loadXML($inputXml);
        $this->expectException(ConfigurationMismatchException::class);
        $this->expectExceptionMessage($exceptionMessage);
        $this->_model->convert($dom);
    }

    /**
     * @return array
     */
    public function convertWithDependencyOnNotExistingIndexerDataProvider()
    {
        return [
            [
                'inputXML' => '<?xml version="1.0" encoding="UTF-8"?><config>'
                    . '<indexer id="indexer_1"><dependencies><indexer id="indexer_3"/></dependencies></indexer>'
                    . '<indexer id="indexer_2"><dependencies><indexer id="indexer_1"/></dependencies></indexer>'
                    . '</config>',
                'exceptionMessage' => "Dependency declaration 'indexer_3' in 'indexer_1' to the non-existing indexer.",
            ],
        ];
    }
}
