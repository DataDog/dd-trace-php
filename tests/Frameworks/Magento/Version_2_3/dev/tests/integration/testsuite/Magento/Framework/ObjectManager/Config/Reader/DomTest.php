<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\ObjectManager\Config\Reader;

use Magento\Framework\Phrase;

/**
 * Class DomTest @covers \Magento\Framework\ObjectManager\Config\Reader\Dom
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DomTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\ObjectManager\Config\Reader\Dom
     */
    protected $_model;

    /**
     * @var array
     */
    protected $_fileList;

    /**
     * @var \Magento\Framework\App\Arguments\FileResolver\Primary
     */
    protected $_fileResolverMock;

    /**
     * @var \DOMDocument
     */
    protected $_mergedConfig;

    /**
     * @var \Magento\Framework\App\Arguments\ValidationState
     */
    protected $_validationState;

    /**
     * @var \Magento\Framework\ObjectManager\Config\SchemaLocator
     */
    protected $_schemaLocator;

    /**
     * @var \Magento\Framework\ObjectManager\Config\Mapper\Dom
     */
    protected $_mapper;

    protected function setUp(): void
    {
        $fixturePath = realpath(__DIR__ . '/../../_files') . '/';
        $this->_fileList = [
            file_get_contents($fixturePath . 'config_one.xml'),
            file_get_contents($fixturePath . 'config_two.xml'),
        ];

        $this->_fileResolverMock = $this->createMock(\Magento\Framework\App\Arguments\FileResolver\Primary::class);
        $this->_fileResolverMock->expects($this->once())->method('get')->willReturn($this->_fileList);

        /** @var Phrase\Renderer\Composite|\PHPUnit\Framework\MockObject\MockObject $renderer */
        $renderer = $this->getMockBuilder(Phrase\Renderer\Composite::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** check arguments won't be translated for ObjectManager, even if has attribute 'translate'=true. */
        $renderer->expects(self::never())
            ->method('render');
        Phrase::setRenderer($renderer);

        $this->_mapper = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\ObjectManager\Config\Mapper\Dom::class
        );
        $this->_validationState = new \Magento\Framework\App\Arguments\ValidationState(
            \Magento\Framework\App\State::MODE_DEFAULT
        );
        $this->_schemaLocator = new \Magento\Framework\ObjectManager\Config\SchemaLocator();

        $this->_mergedConfig = new \DOMDocument();
        $this->_mergedConfig->load($fixturePath . 'config_merged.xml');
    }

    public function testRead()
    {
        $model = new \Magento\Framework\ObjectManager\Config\Reader\Dom(
            $this->_fileResolverMock,
            $this->_mapper,
            $this->_schemaLocator,
            $this->_validationState
        );
        $this->assertEquals($this->_mapper->convert($this->_mergedConfig), $model->read('scope'));
    }
}
