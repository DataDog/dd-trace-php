<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Config\Test\Unit\Model\Config;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\ScopeDefiner;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Data;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\FlyweightFactory;
use Magento\Config\Model\Config\Structure\Element\Iterator\Tab as TabIterator;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Config\Model\Config\Structure\ElementInterface;
use PHPUnit\Framework\MockObject\MockObject as Mock;
use PHPUnit\Framework\TestCase;

/**
 * Test for Structure.
 *
 * @see Structure
 */
class StructureTest extends TestCase
{
    /**
     * @var Structure|Mock
     */
    protected $_model;

    /**
     * @var FlyweightFactory|Mock
     */
    protected $_flyweightFactory;

    /**
     * @var TabIterator|Mock
     */
    protected $_tabIteratorMock;

    /**
     * @var Data|Mock
     */
    protected $_structureDataMock;

    /**
     * @var ScopeDefiner|Mock
     */
    protected $_scopeDefinerMock;

    /**
     * @var array
     */
    protected $_structureData;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->_flyweightFactory = $this->getMockBuilder(FlyweightFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->_tabIteratorMock = $this->getMockBuilder(TabIterator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->_structureDataMock = $this->getMockBuilder(Data::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->_scopeDefinerMock = $this->getMockBuilder(ScopeDefiner::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->_structureData = require dirname(__DIR__) . '/_files/converted_config.php';

        $this->_scopeDefinerMock->expects($this->any())
            ->method('getScope')
            ->willReturn('scope');
        $this->_structureDataMock->expects($this->once())
            ->method('get')
            ->willReturn($this->_structureData['config']['system']);

        $this->_model = new Structure(
            $this->_structureDataMock,
            $this->_tabIteratorMock,
            $this->_flyweightFactory,
            $this->_scopeDefinerMock
        );
    }

    /**
     * Verify tabs build section tree
     *
     * @return void
     */
    public function testGetTabsBuildsSectionTree(): void
    {
        $expected = ['tab1' => ['children' => ['section1' => ['tab' => 'tab1']]]];

        $this->_structureDataMock = $this->getMockBuilder(Data::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->_structureDataMock->expects($this->any())
            ->method('get')
            ->willReturn(
                ['sections' => ['section1' => ['tab' => 'tab1']], 'tabs' => ['tab1' => []]]
            );
        $this->_tabIteratorMock->expects($this->once())
            ->method('setElements')
            ->with($expected);

        $model = new Structure(
            $this->_structureDataMock,
            $this->_tabIteratorMock,
            $this->_flyweightFactory,
            $this->_scopeDefinerMock
        );

        $this->assertEquals($this->_tabIteratorMock, $model->getTabs());
    }

    /**
     * Verify get section list method
     *
     * @return void
     */
    public function testGetSectionList(): void
    {
        $expected = [
            'section1_child_id_1' => true,
            'section1_child_id_2' => true,
            'section1_child_id_3' => true,
            'section2_child_id_1' => true
        ];

        $this->_structureDataMock = $this->getMockBuilder(Data::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->_structureDataMock->expects($this->any())
            ->method('get')
            ->willReturn(
                [
                    'sections' => [
                        'section1' => [
                            'children' => [
                                'child_id_1' => 'child_data',
                                'child_id_2' => 'child_data',
                                'child_id_3' => 'child_data'
                            ]
                        ],
                        'section2' => [
                            'children' => [
                                'child_id_1' => 'child_data'
                            ]
                        ],
                    ]
                ]
            );

        $model = new Structure(
            $this->_structureDataMock,
            $this->_tabIteratorMock,
            $this->_flyweightFactory,
            $this->_scopeDefinerMock
        );

        $this->assertEquals($expected, $model->getSectionList());
    }

    /**
     * Verify Get Element return empty element if element is requested
     *
     * @param string $path
     * @param string $expectedType
     * @param string $expectedId
     * @param string $expectedPath
     * @dataProvider emptyElementDataProvider
     */
    public function testGetElementReturnsEmptyElementIfNotExistingElementIsRequested(
        $path,
        $expectedType,
        $expectedId,
        $expectedPath
    ) {
        $elementMock = $this->getElementReturnsEmptyElementIfNotExistingElementIsRequested(
            $expectedType,
            $expectedId,
            $expectedPath
        );

        $this->assertEquals($elementMock, $this->_model->getElement($path));
    }

    /**
     * Verify get Element return empty by path element if not exist
     *
     * @param string $path
     * @param string $expectedType
     * @param string $expectedId
     * @param string $expectedPath
     * @dataProvider emptyElementDataProvider
     */
    public function testGetElementReturnsEmptyByConfigPathElementIfNotExistingElementIsRequested(
        $path,
        $expectedType,
        $expectedId,
        $expectedPath
    ) {
        $elementMock = $this->getElementReturnsEmptyElementIfNotExistingElementIsRequested(
            $expectedType,
            $expectedId,
            $expectedPath
        );

        $this->assertEquals($elementMock, $this->_model->getElementByConfigPath($path));
    }

    /**
     * Verify Element return e,pty element if not exists
     *
     * @param string $expectedType
     * @param string $expectedId
     * @param string $expectedPath
     * @return Mock
     */
    private function getElementReturnsEmptyElementIfNotExistingElementIsRequested(
        $expectedType,
        $expectedId,
        $expectedPath
    ) {
        $expectedConfig = ['id' => $expectedId, 'path' => $expectedPath, '_elementType' => $expectedType];

        $elementMock = $this->getMockBuilder(ElementInterface::class)
            ->getMockForAbstractClass();
        $elementMock->expects($this->once())
            ->method('setData')
            ->with($expectedConfig);
        $this->_flyweightFactory->expects($this->once())
            ->method('create')
            ->with($expectedType)
            ->willReturn($elementMock);

        return $elementMock;
    }

    /**
     * @return array
     */
    public function emptyElementDataProvider()
    {
        return [
            ['someSection/group_1/nonexisting_field', 'field', 'nonexisting_field', 'someSection/group_1'],
            ['section_1/group_1/nonexisting_field', 'field', 'nonexisting_field', 'section_1/group_1'],
            ['section_1/nonexisting_group', 'group', 'nonexisting_group', 'section_1'],
            ['nonexisting_section', 'section', 'nonexisting_section', '']
        ];
    }

    /**
     * Verify get element returns proper element by path
     *
     * @return void
     */
    public function testGetElementReturnsProperElementByPath(): void
    {
        $elementMock = $this->getElementPathReturnsProperElementByPath();

        $this->assertEquals($elementMock, $this->_model->getElement('section_1/group_level_1/field_3'));
    }

    /**
     * Verify get element by config path return proper path
     *
     * @return void
     */
    public function testGetElementByConfigPathReturnsProperElementByPath(): void
    {
        $elementMock = $this->getElementPathReturnsProperElementByPath();

        $this->assertEquals($elementMock, $this->_model->getElementByConfigPath('section_1/group_level_1/field_3'));
    }

    /**
     * Build mock element
     *
     * @return Mock
     */
    private function getElementPathReturnsProperElementByPath()
    {
        $section = $this->_structureData['config']['system']['sections']['section_1'];
        $fieldData = $section['children']['group_level_1']['children']['field_3'];

        $elementMock = $this->getMockBuilder(Field::class)
            ->disableOriginalConstructor()
            ->getMock();

        $elementMock->expects($this->once())
            ->method('setData')
            ->with($fieldData, 'scope');
        $this->_flyweightFactory->expects($this->once())
            ->method('create')
            ->with('field')
            ->willReturn($elementMock);

        return $elementMock;
    }

    /**
     * Verefy get element by path part
     *
     * @return void
     */
    public function testGetElementByPathPartsIfSectionDataIsEmpty(): void
    {
        $fieldData = [
            'id' => 'field_3',
            'path' => 'section_1/group_level_1',
            '_elementType' => 'field',
        ];
        $pathParts = explode('/', 'section_1/group_level_1/field_3');

        $elementMock = $this->getMockBuilder(Field::class)
            ->disableOriginalConstructor()
            ->getMock();
        $structureDataMock = $this->getMockBuilder(Data::class)
            ->disableOriginalConstructor()
            ->getMock();

        $elementMock->expects($this->once())
            ->method('setData')
            ->with($fieldData, 'scope');
        $this->_flyweightFactory->expects($this->once())
            ->method('create')
            ->with('field')
            ->willReturn($elementMock);
        $structureDataMock->expects($this->once())
            ->method('get')
            ->willReturn([]);

        $structureMock = new Structure(
            $structureDataMock,
            $this->_tabIteratorMock,
            $this->_flyweightFactory,
            $this->_scopeDefinerMock
        );

        $this->assertEquals($elementMock, $structureMock->getElementByPathParts($pathParts));
    }

    public function testGetFirstSectionReturnsFirstAllowedSection()
    {
        $tabMock = $this->getMockBuilder(Structure\Element\Tab::class)
            ->disableOriginalConstructor()
            ->setMethods(['current', 'getChildren', 'rewind'])
            ->getMock();

        $tabMock->expects($this->any())
            ->method('getChildren')
            ->willReturnSelf();
        $tabMock->expects($this->once())
            ->method('rewind');
        $section = $this->getMockBuilder(Section::class)
            ->disableOriginalConstructor()
            ->setMethods(['isVisible', 'getData'])
            ->getMock();
        $section->expects($this->any())
            ->method('isVisible')
            ->willReturn(true);
        $section->expects($this->any())
            ->method('getData')
            ->willReturn('currentSection');
        $tabMock->expects($this->any())
            ->method('current')
            ->willReturn($section);
        $this->_tabIteratorMock->expects($this->once())
            ->method('rewind');
        $this->_tabIteratorMock->expects($this->once())
            ->method('current')
            ->willReturn($tabMock);

        $this->assertEquals('currentSection', $this->_model->getFirstSection()->getData());
    }

    /**
     * Verify get element return element by path caches object
     *
     * @return void
     */
    public function testGetElementReturnsProperElementByPathCachesObject(): void
    {
        $elementMock = $this->getElementReturnsProperElementByPathCachesObject();

        $this->assertEquals($elementMock, $this->_model->getElement('section_1/group_level_1/field_3'));
        $this->assertEquals($elementMock, $this->_model->getElement('section_1/group_level_1/field_3'));
    }

    /**
     * Verify Get Element by id returns proper element
     *
     * @return void
     */
    public function testGetElementByConfigPathReturnsProperElementByPathCachesObject(): void
    {
        $elementMock = $this->getElementReturnsProperElementByPathCachesObject();

        $this->assertEquals($elementMock, $this->_model->getElementByConfigPath('section_1/group_level_1/field_3'));
        $this->assertEquals($elementMock, $this->_model->getElementByConfigPath('section_1/group_level_1/field_3'));
    }

    /**
     * @return Mock
     */
    private function getElementReturnsProperElementByPathCachesObject()
    {
        $section = $this->_structureData['config']['system']['sections']['section_1'];
        $fieldData = $section['children']['group_level_1']['children']['field_3'];

        $elementMock = $this->getMockBuilder(Field::class)
            ->disableOriginalConstructor()
            ->getMock();

        $elementMock->expects($this->once())
            ->method('setData')
            ->with($fieldData, 'scope');
        $this->_flyweightFactory->expects($this->once())
            ->method('create')
            ->with('field')
            ->willReturn($elementMock);

        return $elementMock;
    }

    /**
     * @param $attributeName
     * @param $attributeValue
     * @param $paths
     * @dataProvider getFieldPathsByAttributeDataProvider
     */
    public function testGetFieldPathsByAttribute($attributeName, $attributeValue, $paths)
    {
        $this->assertEquals($paths, $this->_model->getFieldPathsByAttribute($attributeName, $attributeValue));
    }

    /**
     * DataProvider
     *
     * @return array
     */
    public function getFieldPathsByAttributeDataProvider()
    {
        return [
            [
                'backend_model',
                Encrypted::class,
                [
                    'section_1/group_1/field_2',
                    'section_1/group_level_1/group_level_2/group_level_3/field_3_1_1',
                    'section_2/group_3/field_4',
                ]
            ],
            ['attribute_2', 'test_value_2', ['section_2/group_3/field_4']]
        ];
    }

    /**
     * Verify get Fields paths method
     *
     * @dataProvider getFieldPaths
     * @param array $expected
     * @return void
     */
    public function testGetFieldPaths(array $expected): void
    {
        $this->assertSame(
            $expected,
            $this->_model->getFieldPaths()
        );
    }

    /**
     * dataprovider for Field Paths
     *
     * @return array
     */
    public function getFieldPaths(): array
    {
        return  [
            [
                [
                    'section/group/field2' => [
                        'field_2'
                    ],
                    'field_3' => [
                        'field_3',
                        'field_3'
                    ],
                    'field_3_1' => [
                        'field_3_1'
                    ],
                    'field_3_1_1' => [
                        'field_3_1_1'
                    ],
                    'section/group/field4' => [
                        'field_4',
                    ],
                    'field_5' => [
                        'field_5',
                        'field_5'
                    ]
                ]
            ]
        ];
    }
}
