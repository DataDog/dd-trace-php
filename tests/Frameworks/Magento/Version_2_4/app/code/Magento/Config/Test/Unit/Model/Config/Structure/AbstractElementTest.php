<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Config\Test\Unit\Model\Config\Structure;

use Magento\Config\Model\Config\Structure\AbstractElement;
use Magento\Config\Model\Config\Structure\ElementVisibilityInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AbstractElementTest extends TestCase
{
    /**
     * @var AbstractElement
     */
    protected $_model;

    /**
     * @var MockObject
     */
    protected $storeManagerMock;

    /**
     * @var AbstractElement|MockObject
     */
    protected $moduleManagerMock;

    /**
     * @var ElementVisibilityInterface|MockObject
     */
    private $elementVisibilityMock;

    protected function setUp(): void
    {
        $this->elementVisibilityMock = $this->getMockBuilder(ElementVisibilityInterface::class)
            ->getMockForAbstractClass();
        $this->storeManagerMock = $this->createMock(StoreManager::class);
        $this->moduleManagerMock = $this->createPartialMock(
            Manager::class,
            ['isOutputEnabled']
        );

        $this->_model = $this->getMockForAbstractClass(
            AbstractElement::class,
            [
                'storeManager' => $this->storeManagerMock,
                'moduleManager' => $this->moduleManagerMock,
            ]
        );

        $objectManagerHelper = new ObjectManagerHelper($this);
        $objectManagerHelper->setBackwardCompatibleProperty(
            $this->_model,
            'elementVisibility',
            $this->elementVisibilityMock,
            AbstractElement::class
        );
    }

    public function testGetId()
    {
        $this->assertEquals('', $this->_model->getId());
        $this->_model->setData(['id' => 'someId'], 'someScope');
        $this->assertEquals('someId', $this->_model->getId());
    }

    public function testGetLabelTranslatesLabel()
    {
        $this->assertEquals('', $this->_model->getLabel());
        $this->_model->setData(['label' => 'some_label'], 'someScope');
        $this->assertEquals(__('some_label'), $this->_model->getLabel());
    }

    public function testGetCommentTranslatesComment()
    {
        $this->assertEquals('', $this->_model->getComment());
        $this->_model->setData(['comment' => 'some_comment'], 'someScope');
        $this->assertEquals(__('some_comment'), $this->_model->getComment());
    }

    public function testGetFrontEndModel()
    {
        $this->_model->setData(['frontend_model' => 'frontend_model_name'], 'store');
        $this->assertEquals('frontend_model_name', $this->_model->getFrontendModel());
    }

    public function testGetAttribute()
    {
        $this->_model->setData(
            ['id' => 'elementId', 'label' => 'Element Label', 'someAttribute' => 'Some attribute value'],
            'someScope'
        );
        $this->assertEquals('elementId', $this->_model->getAttribute('id'));
        $this->assertEquals('Element Label', $this->_model->getAttribute('label'));
        $this->assertEquals('Some attribute value', $this->_model->getAttribute('someAttribute'));
        $this->assertNull($this->_model->getAttribute('nonexistingAttribute'));
    }

    public function testIsVisibleReturnsTrueInSingleStoreModeForNonHiddenElements()
    {
        $this->storeManagerMock->expects($this->once())->method('isSingleStoreMode')->willReturn(true);
        $this->_model->setData(
            ['showInDefault' => 1, 'showInStore' => 0, 'showInWebsite' => 0],
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        $this->assertTrue($this->_model->isVisible());
    }

    public function testIsVisibleReturnsFalseInSingleStoreModeForHiddenElements()
    {
        $this->storeManagerMock->expects($this->once())->method('isSingleStoreMode')->willReturn(true);
        $this->_model->setData(
            ['hide_in_single_store_mode' => 1, 'showInDefault' => 1, 'showInStore' => 0, 'showInWebsite' => 0],
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        $this->assertFalse($this->_model->isVisible());
    }

    /**
     * Invisible elements is contains showInDefault="0" showInWebsite="0" showInStore="0"
     */
    public function testIsVisibleReturnsFalseInSingleStoreModeForInvisibleElements()
    {
        $this->storeManagerMock->expects($this->once())->method('isSingleStoreMode')->willReturn(true);
        $this->_model->setData(
            ['showInDefault' => 0, 'showInStore' => 0, 'showInWebsite' => 0],
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        $this->assertFalse($this->_model->isVisible());
    }

    /**
     * @param array $settings
     * @param string $scope
     * @dataProvider isVisibleReturnsTrueForProperScopesDataProvider
     */
    public function testIsVisibleReturnsTrueForProperScopes($settings, $scope)
    {
        $this->_model->setData($settings, $scope);
        $this->assertTrue($this->_model->isVisible());
    }

    /**
     * @return array
     */
    public function isVisibleReturnsTrueForProperScopesDataProvider()
    {
        return [
            [
                ['showInDefault' => 1, 'showInStore' => 0, 'showInWebsite' => 0],
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ],
            [
                ['showInDefault' => 0, 'showInStore' => 1, 'showInWebsite' => 0],
                ScopeInterface::SCOPE_STORE
            ],
            [
                ['showInDefault' => 0, 'showInStore' => 0, 'showInWebsite' => 1],
                ScopeInterface::SCOPE_WEBSITE
            ]
        ];
    }

    /**
     * @param array $settings
     * @param string $scope
     * @dataProvider isVisibleReturnsFalseForNonProperScopesDataProvider
     */
    public function testIsVisibleReturnsFalseForNonProperScopes($settings, $scope)
    {
        $this->_model->setData($settings, $scope);
        $this->assertFalse($this->_model->isVisible());
    }

    /**
     * @return array
     */
    public function isVisibleReturnsFalseForNonProperScopesDataProvider()
    {
        return [
            [
                ['showInDefault' => 0, 'showInStore' => 1, 'showInWebsite' => 1],
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ],
            [
                ['showInDefault' => 1, 'showInStore' => 0, 'showInWebsite' => 1],
                ScopeInterface::SCOPE_STORE
            ],
            [
                ['showInDefault' => 1, 'showInStore' => 1, 'showInWebsite' => 0],
                ScopeInterface::SCOPE_WEBSITE
            ]
        ];
    }

    public function testIsVisibleReturnFalseIfModuleNotEnabled()
    {
        $this->moduleManagerMock->expects($this->once())
            ->method('isOutputEnabled')
            ->with('test_module')
            ->willReturn(false);
        $this->_model->setData(
            [
                'showInDefault' => 1,
                'showInStore' => 0,
                'showInWebsite' => 0,
                'if_module_enabled' => 'test_module',
            ],
            'default'
        );
        $this->assertFalse($this->_model->isVisible());
    }

    public function testIsVisibleVisibilityIsHiddenTrue()
    {
        $this->elementVisibilityMock->expects($this->once())
            ->method('isHidden')
            ->willReturn(true);
        $this->assertFalse($this->_model->isVisible());
    }

    public function testGetClass()
    {
        $this->assertEquals('', $this->_model->getClass());
        $this->_model->setData(['class' => 'some_class'], 'store');
        $this->assertEquals('some_class', $this->_model->getClass());
    }

    public function testGetPathBuildsFullPath()
    {
        $this->_model->setData(['path' => 'section/group', 'id' => 'fieldId'], 'scope');
        $this->assertEquals('section/group/prefix_fieldId', $this->_model->getPath('prefix_'));
    }
}
