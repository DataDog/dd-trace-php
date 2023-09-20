<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Tests for \Magento\Framework\Data\Form\Element\Factory
 */
namespace Magento\Framework\Data\Test\Unit\Form\Element;

class FactoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_objectManagerMock;

    /**
     * @var \Magento\Framework\Data\Form\Element\Factory
     */
    protected $_factory;

    protected function setUp(): void
    {
        $this->_objectManagerMock =
            $this->createPartialMock(\Magento\Framework\ObjectManager\ObjectManager::class, ['create']);
        $this->_factory = new \Magento\Framework\Data\Form\Element\Factory($this->_objectManagerMock);
    }

    /**
     * @param string $type
     * @dataProvider createPositiveDataProvider
     */
    public function testCreatePositive($type)
    {
        $className = 'Magento\Framework\Data\Form\Element\\' . ucfirst($type);
        $elementMock = $this->createMock($className);
        $this->_objectManagerMock->expects(
            $this->once()
        )->method(
            'create'
        )->with(
            $className,
            []
        )->willReturn(
            $elementMock
        );
        $element = $this->_factory->create($type);
        $this->assertSame($elementMock, $element);
        unset($elementMock, $element);
    }

    /**
     * @param string $type
     * @dataProvider createPositiveDataProvider
     */
    public function testCreatePositiveWithNotEmptyConfig($type)
    {
        $config = ['data' => ['attr1' => 'attr1', 'attr2' => 'attr2']];
        $className = 'Magento\Framework\Data\Form\Element\\' . ucfirst($type);
        $elementMock = $this->createMock($className);
        $this->_objectManagerMock->expects(
            $this->once()
        )->method(
            'create'
        )->with(
            $className,
            $config
        )->willReturn(
            $elementMock
        );
        $element = $this->_factory->create($type, $config);
        $this->assertSame($elementMock, $element);
        unset($elementMock, $element);
    }

    /**
     * @return array
     */
    public function createPositiveDataProvider()
    {
        return [
            'button' => ['button'],
            'checkbox' => ['checkbox'],
            'checkboxes' => ['checkboxes'],
            'column' => ['column'],
            'date' => ['date'],
            'editablemultiselect' => ['editablemultiselect'],
            'editor' => ['editor'],
            'fieldset' => ['fieldset'],
            'file' => ['file'],
            'gallery' => ['gallery'],
            'hidden' => ['hidden'],
            'image' => ['image'],
            'imagefile' => ['imagefile'],
            'label' => ['label'],
            'link' => ['link'],
            'multiline' => ['multiline'],
            'multiselect' => ['multiselect'],
            'note' => ['note'],
            'obscure' => ['obscure'],
            'password' => ['password'],
            'radio' => ['radio'],
            'radios' => ['radios'],
            'reset' => ['reset'],
            'select' => ['select'],
            'submit' => ['submit'],
            'text' => ['text'],
            'textarea' => ['textarea'],
            'time' => ['time']
        ];
    }

    /**
     * @param string $type
     * @dataProvider createExceptionReflectionExceptionDataProvider
     */
    public function testCreateExceptionReflectionException($type)
    {
        $this->expectException(\ReflectionException::class);

        $this->_objectManagerMock->expects(
            $this->once()
        )->method(
            'create'
        )->with(
            $type,
            []
        )->will(
            $this->throwException(new \ReflectionException())
        );
        $this->_factory->create($type);
    }

    /**
     * @return array
     */
    public function createExceptionReflectionExceptionDataProvider()
    {
        return [
            'factory' => ['factory'],
            'collection' => ['collection'],
            'abstract' => ['abstract']
        ];
    }

    /**
     * @param string $type
     * @dataProvider createExceptionInvalidArgumentDataProvider
     */
    public function testCreateExceptionInvalidArgument($type)
    {
        $this->expectException(\InvalidArgumentException::class);

        $elementMock = $this->createMock($type);
        $this->_objectManagerMock->expects(
            $this->once()
        )->method(
            'create'
        )->with(
            $type,
            []
        )->willReturn(
            $elementMock
        );
        $this->_factory->create($type);
    }

    /**
     * @return array
     */
    public function createExceptionInvalidArgumentDataProvider()
    {
        return [
            \Magento\Framework\Data\Form\Element\Factory::class => [
                \Magento\Framework\Data\Form\Element\Factory::class
            ],
            \Magento\Framework\Data\Form\Element\Collection::class => [
                \Magento\Framework\Data\Form\Element\Collection::class
            ]
        ];
    }
}
