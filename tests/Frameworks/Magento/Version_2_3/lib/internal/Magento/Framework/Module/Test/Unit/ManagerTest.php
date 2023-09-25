<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Module\Test\Unit;

/**
 * Manager test
 */
class ManagerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * XPath in the configuration of a module output flag
     */
    const XML_PATH_OUTPUT_ENABLED = 'custom/is_module_output_enabled';

    /**
     * @var \Magento\Framework\Module\Manager
     */
    private $_model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $_moduleList;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $_outputConfig;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->_moduleList = $this->getMockForAbstractClass(\Magento\Framework\Module\ModuleListInterface::class);
        $this->_moduleList->expects($this->any())
            ->method('getOne')
            ->willReturnMap(
                
                    [
                        ['Module_One', ['name' => 'One_Module', 'setup_version' => '1']],
                        ['Module_Two', ['name' => 'Two_Module', 'setup_version' => '2']],
                        ['Module_Three', ['name' => 'Two_Three']],
                    ]
                
            );
        $this->_outputConfig = $this->getMockForAbstractClass(\Magento\Framework\Module\Output\ConfigInterface::class);
        $this->_model = new \Magento\Framework\Module\Manager(
            $this->_outputConfig,
            $this->_moduleList,
            [
                'Module_Two' => self::XML_PATH_OUTPUT_ENABLED,
            ]
        );
    }

    public function testIsEnabled()
    {
        $this->_moduleList->expects($this->exactly(2))->method('has')->willReturnMap(
            
                [
                    ['Module_Exists', true],
                    ['Module_NotExists', false],
                ]
            
        );
        $this->assertTrue($this->_model->isEnabled('Module_Exists'));
        $this->assertFalse($this->_model->isEnabled('Module_NotExists'));
    }

    public function testIsOutputEnabledReturnsFalseForDisabledModule()
    {
        $this->_outputConfig->expects($this->any())->method('isSetFlag')->willReturn(true);
        $this->assertFalse($this->_model->isOutputEnabled('Disabled_Module'));
    }

    /**
     * @param bool $configValue
     * @param bool $expectedResult
     * @dataProvider isOutputEnabledGenericConfigPathDataProvider
     */
    public function testIsOutputEnabledGenericConfigPath($configValue, $expectedResult)
    {
        $this->_moduleList->expects($this->once())->method('has')->willReturn(true);
        $this->_outputConfig->expects($this->once())
            ->method('isEnabled')
            ->with('Module_One')
            ->willReturn($configValue);
        $this->assertEquals($expectedResult, $this->_model->isOutputEnabled('Module_One'));
    }

    /**
     * @return array
     */
    public function isOutputEnabledGenericConfigPathDataProvider()
    {
        return ['output disabled' => [true, false], 'output enabled' => [false, true]];
    }

    /**
     * @param bool $configValue
     * @param bool $expectedResult
     * @dataProvider isOutputEnabledCustomConfigPathDataProvider
     */
    public function testIsOutputEnabledCustomConfigPath($configValue, $expectedResult)
    {
        $this->_moduleList->expects($this->once())->method('has')->willReturn(true);
        $this->_outputConfig->expects($this->at(0))
            ->method('isSetFlag')
            ->with(self::XML_PATH_OUTPUT_ENABLED)
            ->willReturn($configValue);
        $this->assertEquals($expectedResult, $this->_model->isOutputEnabled('Module_Two'));
    }

    /**
     * @return array
     */
    public function isOutputEnabledCustomConfigPathDataProvider()
    {
        return [
            'path literal, output disabled' => [false, false],
            'path literal, output enabled'  => [true, true],
        ];
    }
}
