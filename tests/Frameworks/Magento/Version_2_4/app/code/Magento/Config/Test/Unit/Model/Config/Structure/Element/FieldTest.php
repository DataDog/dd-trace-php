<?php declare(strict_types=1);
/**
 * \Magento\Config\Model\Config\Structure\Element\Field
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Config\Test\Unit\Model\Config\Structure\Element;

use Magento\Config\Model\Config\BackendFactory;
use Magento\Config\Model\Config\CommentFactory;
use Magento\Config\Model\Config\CommentInterface;
use Magento\Config\Model\Config\SourceFactory;
use Magento\Config\Model\Config\Structure\Element\Dependency\Mapper;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\Data\Form\Element\Text;
use Magento\Framework\DataObject;
use Magento\Framework\Option\ArrayInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\Element\BlockFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FieldTest extends TestCase
{
    const FIELD_TEST_CONSTANT = "field test constant";

    /**
     * @var Field
     */
    protected $_model;

    /**
     * @var MockObject
     */
    protected $_backendFactoryMock;

    /**
     * @var MockObject
     */
    protected $_sourceFactoryMock;

    /**
     * @var MockObject
     */
    protected $_commentFactoryMock;

    /**
     * @var MockObject
     */
    protected $_blockFactoryMock;

    /**
     * @var MockObject
     */
    protected $_depMapperMock;

    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);

        $this->_backendFactoryMock = $this->createMock(BackendFactory::class);
        $this->_sourceFactoryMock = $this->createMock(SourceFactory::class);
        $this->_commentFactoryMock = $this->createMock(CommentFactory::class);
        $this->_blockFactoryMock = $this->createMock(BlockFactory::class);
        $this->_depMapperMock = $this->createMock(
            Mapper::class
        );

        $this->_model = $objectManager->getObject(
            Field::class,
            [
                'backendFactory' => $this->_backendFactoryMock,
                'sourceFactory' => $this->_sourceFactoryMock,
                'commentFactory' => $this->_commentFactoryMock,
                'blockFactory' => $this->_blockFactoryMock,
                'dependencyMapper' => $this->_depMapperMock,
            ]
        );
    }

    protected function tearDown(): void
    {
        unset($this->_backendFactoryMock);
        unset($this->_sourceFactoryMock);
        unset($this->_commentFactoryMock);
        unset($this->_depMapperMock);
        unset($this->_model);
        unset($this->_blockFactoryMock);
    }

    public function testGetLabelTranslatesLabelAndPrefix()
    {
        $this->_model->setData(['label' => 'element label'], 'scope');
        $this->assertEquals(
            __('some prefix') . ' ' . __('element label'),
            $this->_model->getLabel('some prefix')
        );
    }

    public function testGetHintTranslatesElementHint()
    {
        $this->_model->setData(['hint' => 'element hint'], 'scope');
        $this->assertEquals(__('element hint'), $this->_model->getHint());
    }

    public function testGetCommentTranslatesCommentTextIfNoCommentModelIsProvided()
    {
        $this->_model->setData(['comment' => 'element comment'], 'scope');
        $this->assertEquals(__('element comment'), $this->_model->getComment());
    }

    public function testGetCommentRetrievesCommentFromCommentModelIfItsProvided()
    {
        $config = ['comment' => ['model' => 'Model_Name']];
        $this->_model->setData($config, 'scope');
        $commentModelMock = $this->getMockForAbstractClass(CommentInterface::class);
        $commentModelMock->expects(
            $this->once()
        )->method(
            'getCommentText'
        )->with(
            'currentValue'
        )->willReturn(
            'translatedValue'
        );
        $this->_commentFactoryMock->expects(
            $this->once()
        )->method(
            'create'
        )->with(
            'Model_Name'
        )->willReturn(
            $commentModelMock
        );
        $this->assertEquals('translatedValue', $this->_model->getComment('currentValue'));
    }

    public function testGetTooltipRetunrsTranslatedAttributeIfNoBlockIsProvided()
    {
        $this->_model->setData(['tooltip' => 'element tooltip'], 'scope');
        $this->assertEquals(__('element tooltip'), $this->_model->getTooltip());
    }

    public function testGetTypeReturnsTextByDefault()
    {
        $this->assertEquals('text', $this->_model->getType());
    }

    public function testGetTypeReturnsProvidedType()
    {
        $this->_model->setData(['type' => 'some_type'], 'scope');
        $this->assertEquals('some_type', $this->_model->getType());
    }

    public function testGetFrontendClass()
    {
        $this->assertEquals('', $this->_model->getFrontendClass());
        $this->_model->setData(['frontend_class' => 'some class'], 'scope');
        $this->assertEquals('some class', $this->_model->getFrontendClass());
    }

    public function testHasBackendModel()
    {
        $this->assertFalse($this->_model->hasBackendModel());
        $this->_model->setData(['backend_model' => 'some_model'], 'scope');
        $this->assertTrue($this->_model->hasBackendModel());
    }

    public function testGetSectionId()
    {
        $this->_model->setData(['id' => 'fieldId', 'path' => 'sectionId/groupId/subgroupId'], 'scope');
        $this->assertEquals('sectionId', $this->_model->getSectionId());
    }

    public function testGetGroupPath()
    {
        $this->_model->setData(['id' => 'fieldId', 'path' => 'sectionId/groupId/subgroupId'], 'scope');
        $this->assertEquals('sectionId/groupId/subgroupId', $this->_model->getGroupPath());
    }

    public function testGetConfigPath()
    {
        $this->_model->setData(['config_path' => 'custom_config_path'], 'scope');
        $this->assertEquals('custom_config_path', $this->_model->getConfigPath());
    }

    public function testShowInDefault()
    {
        $this->assertFalse($this->_model->showInDefault());
        $this->_model->setData(['showInDefault' => 1], 'scope');
        $this->assertTrue($this->_model->showInDefault());
    }

    public function testShowInWebsite()
    {
        $this->assertFalse($this->_model->showInWebsite());
        $this->_model->setData(['showInWebsite' => 1], 'scope');
        $this->assertTrue($this->_model->showInWebsite());
    }

    public function testShowInStore()
    {
        $this->assertFalse($this->_model->showInStore());
        $this->_model->setData(['showInStore' => 1], 'scope');
        $this->assertTrue($this->_model->showInStore());
    }

    public function testPopulateInput()
    {
        $params = [
            'type' => 'multiselect',
            'can_be_empty' => true,
            'source_model' => 'some_model',
            'someArr' => ['testVar' => 'testVal'],
        ];
        $this->_model->setData($params, 'scope');
        $elementMock = $this->getMockBuilder(Text::class)
            ->addMethods(['setOriginalData'])
            ->disableOriginalConstructor()
            ->getMock();
        unset($params['someArr']);
        $elementMock->expects($this->once())->method('setOriginalData')->with($params);
        $this->_model->populateInput($elementMock);
    }

    public function testHasValidation()
    {
        $this->assertFalse($this->_model->hasValidation());
        $this->_model->setData(['validate' => 'validation class'], 'scope');
        $this->assertTrue($this->_model->hasValidation());
    }

    public function testCanBeEmpty()
    {
        $this->assertFalse($this->_model->canBeEmpty());
        $this->_model->setData(['can_be_empty' => true], 'scope');
        $this->assertTrue($this->_model->canBeEmpty());
    }

    public function testHasSourceModel()
    {
        $this->assertFalse($this->_model->hasSourceModel());
        $this->_model->setData(['source_model' => 'some_model'], 'scope');
        $this->assertTrue($this->_model->hasSourceModel());
    }

    public function testHasOptionsWithSourceModel()
    {
        $this->assertFalse($this->_model->hasOptions());
        $this->_model->setData(['source_model' => 'some_model'], 'scope');
        $this->assertTrue($this->_model->hasOptions());
    }

    public function testHasOptionsWithOptions()
    {
        $this->assertFalse($this->_model->hasOptions());
        $this->_model->setData(['options' => 'some_option'], 'scope');
        $this->assertTrue($this->_model->hasOptions());
    }

    public function testGetOptionsWithOptions()
    {
        $option = [['label' => 'test', 'value' => 0], ['label' => 'test2', 'value' => 1]];
        $expected = [['label' => __('test'), 'value' => 0], ['label' => __('test2'), 'value' => 1]];
        $this->_model->setData(['options' => ['option' => $option]], 'scope');
        $this->assertEquals($expected, $this->_model->getOptions());
    }

    public function testGetOptionsWithConstantValOptions()
    {
        $option = [
            [
                'label' => 'test',
                'value' => sprintf(
                    "{{%s::FIELD_TEST_CONSTANT}}",
                    '\Magento\Config\Test\Unit\Model\Config\Structure\Element\FieldTest'
                ),
            ],
        ];
        $expected = [
            [
                'label' => __('test'),
                'value' => \Magento\Config\Test\Unit\Model\Config\Structure\Element\FieldTest::FIELD_TEST_CONSTANT,
            ],
        ];

        $this->_model->setData(['options' => ['option' => $option]], 'scope');
        $this->assertEquals($expected, $this->_model->getOptions());
    }

    public function testGetOptionsUsesOptionsInterfaceIfNoMethodIsProvided()
    {
        $this->_model->setData(['source_model' => 'Source_Model_Name'], 'scope');
        $sourceModelMock = $this->getMockForAbstractClass(ArrayInterface::class);
        $this->_sourceFactoryMock->expects(
            $this->once()
        )->method(
            'create'
        )->with(
            'Source_Model_Name'
        )->willReturn(
            $sourceModelMock
        );
        $expected = [['label' => 'test', 'value' => 0], ['label' => 'test2', 'value' => 1]];
        $sourceModelMock->expects(
            $this->once()
        )->method(
            'toOptionArray'
        )->with(
            false
        )->willReturn(
            $expected
        );
        $this->assertEquals($expected, $this->_model->getOptions());
    }

    public function testGetOptionsUsesProvidedMethodOfSourceModel()
    {
        $this->_model->setData(
            ['source_model' => 'Source_Model_Name::retrieveElements', 'path' => 'path', 'type' => 'multiselect'],
            'scope'
        );
        $sourceModelMock = $this->getMockBuilder(DataObject::class)
            ->addMethods(['setPath', 'retrieveElements'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->_sourceFactoryMock->expects(
            $this->once()
        )->method(
            'create'
        )->with(
            'Source_Model_Name'
        )->willReturn(
            $sourceModelMock
        );
        $expected = ['testVar1' => 'testVal1', 'testVar2' => ['subvar1' => 'subval1']];
        $sourceModelMock->expects($this->once())->method('setPath')->with('path/');
        $sourceModelMock->expects($this->once())->method('retrieveElements')->willReturn($expected);
        $this->assertEquals($expected, $this->_model->getOptions());
    }

    public function testGetOptionsParsesResultOfProvidedMethodOfSourceModelIfTypeIsNotMultiselect()
    {
        $this->_model->setData(
            ['source_model' => 'Source_Model_Name::retrieveElements', 'path' => 'path', 'type' => 'select'],
            'scope'
        );
        $sourceModelMock = $this->getMockBuilder(DataObject::class)
            ->addMethods(['setPath', 'retrieveElements'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->_sourceFactoryMock->expects(
            $this->once()
        )->method(
            'create'
        )->with(
            'Source_Model_Name'
        )->willReturn(
            $sourceModelMock
        );
        $sourceModelMock->expects($this->once())->method('setPath')->with('path/');
        $sourceModelMock->expects(
            $this->once()
        )->method(
            'retrieveElements'
        )->willReturn(
            ['var1' => 'val1', 'var2' => ['subvar1' => 'subval1']]
        );
        $expected = [['label' => 'val1', 'value' => 'var1'], ['subvar1' => 'subval1']];
        $this->assertEquals($expected, $this->_model->getOptions());
    }

    public function testGetDependenciesWithoutDependencies()
    {
        $this->_depMapperMock->expects($this->never())->method('getDependencies');
    }

    public function testGetDependenciesWithDependencies()
    {
        $fields = [
            'field_4' => [
                'id' => 'section_2/group_3/field_4',
                'value' => 'someValue',
                'dependPath' => ['section_2', 'group_3', 'field_4'],
            ],
            'field_1' => [
                'id' => 'section_1/group_3/field_1',
                'value' => 'someValue',
                'dependPath' => ['section_1', 'group_3', 'field_1'],
            ],
        ];
        $this->_model->setData(['depends' => ['fields' => $fields]], 0);
        $this->_depMapperMock->expects(
            $this->once()
        )->method(
            'getDependencies'
        )->with(
            $fields,
            'test_scope',
            'test_prefix'
        )->willReturnArgument(
            0
        );

        $this->assertEquals($fields, $this->_model->getDependencies('test_prefix', 'test_scope'));
    }

    public function testIsAdvanced()
    {
        $this->_model->setData([], 'scope');
        $this->assertFalse($this->_model->isAdvanced());

        $this->_model->setData(['advanced' => true], 'scope');
        $this->assertTrue($this->_model->isAdvanced());

        $this->_model->setData(['advanced' => false], 'scope');
        $this->assertFalse($this->_model->isAdvanced());
    }

    public function testGetValidation()
    {
        $this->_model->setData([], 'scope');
        $this->assertNull($this->_model->getValidation());

        $this->_model->setData(['validate' => 'validate'], 'scope');
        $this->assertEquals('validate', $this->_model->getValidation());
    }
}
