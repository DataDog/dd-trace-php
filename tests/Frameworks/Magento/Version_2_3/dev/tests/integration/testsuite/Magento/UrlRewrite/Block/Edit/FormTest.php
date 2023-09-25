<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\UrlRewrite\Block\Edit;

/**
 * Test for \Magento\UrlRewrite\Block\Edit\FormTest
 * @magentoAppArea adminhtml
 */
class FormTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Get form instance
     *
     * @param array $args
     * @return \Magento\Framework\Data\Form
     */
    protected function _getFormInstance($args = [])
    {
        /** @var $layout \Magento\Framework\View\Layout */
        $layout = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\View\LayoutInterface::class
        );
        /** @var $block \Magento\UrlRewrite\Block\Edit\Form */
        $block = $layout->createBlock(\Magento\UrlRewrite\Block\Edit\Form::class, 'block', ['data' => $args]);
        $block->setTemplate(null);
        $block->toHtml();
        return $block->getForm();
    }

    /**
     * Test that form was prepared correctly
     * @magentoAppIsolation enabled
     */
    public function testPrepareForm()
    {
        // Test form was configured correctly
        $form = $this->_getFormInstance(['url_rewrite' => new \Magento\Framework\DataObject(['id' => 3])]);
        $this->assertInstanceOf(\Magento\Framework\Data\Form::class, $form);
        $this->assertNotEmpty($form->getAction());
        $this->assertEquals('edit_form', $form->getId());
        $this->assertEquals('post', $form->getMethod());
        $this->assertTrue($form->getUseContainer());
        $this->assertStringContainsString('/id/3', $form->getAction());

        // Check all expected form elements are present
        $expectedElements = [
            'store_id',
            'entity_type',
            'entity_id',
            'request_path',
            'target_path',
            'redirect_type',
            'description',
        ];
        foreach ($expectedElements as $expectedElement) {
            $this->assertNotNull($form->getElement($expectedElement));
        }
    }

    /**
     * Check session data restoring
     * @magentoAppIsolation enabled
     */
    public function testSessionRestore()
    {
        // Set urlrewrite data to session
        $sessionValues = [
            'store_id' => 1,
            'entity_type' => 'entity_type',
            'entity_id' => 'entity_id',
            'request_path' => 'request_path',
            'target_path' => 'target_path',
            'redirect_type' => 'redirect_type',
            'description' => 'description',
        ];
        \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Backend\Model\Session::class
        )->setUrlRewriteData(
            $sessionValues
        );
        // Re-init form to use newly set session data
        $form = $this->_getFormInstance(['url_rewrite' => new \Magento\Framework\DataObject()]);

        // Check that all fields values are restored from session
        foreach ($sessionValues as $field => $value) {
            $this->assertEquals($value, $form->getElement($field)->getValue());
        }
    }

    /**
     * Test store element is hidden when only one store available
     *
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store general/single_store_mode/enabled 1
     */
    public function testStoreElementSingleStore()
    {
        $form = $this->_getFormInstance(['url_rewrite' => new \Magento\Framework\DataObject(['id' => 3])]);
        /** @var $storeElement \Magento\Framework\Data\Form\Element\AbstractElement */
        $storeElement = $form->getElement('store_id');
        $this->assertInstanceOf(\Magento\Framework\Data\Form\Element\Hidden::class, $storeElement);

        // Check that store value set correctly
        $defaultStore = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Store\Model\StoreManagerInterface::class
        )->getStore(
            true
        )->getId();
        $this->assertEquals($defaultStore, $storeElement->getValue());
    }

    /**
     * Test store selection is available and correctly configured
     *
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Store/_files/core_fixturestore.php
     */
    public function testStoreElementMultiStores()
    {
        $form = $this->_getFormInstance(['url_rewrite' => new \Magento\Framework\DataObject(['id' => 3])]);
        /** @var $storeElement \Magento\Framework\Data\Form\Element\AbstractElement */
        $storeElement = $form->getElement('store_id');

        // Check store selection elements has correct type
        $this->assertInstanceOf(\Magento\Framework\Data\Form\Element\Select::class, $storeElement);

        // Check store selection elements has correct renderer
        $this->assertInstanceOf(
            \Magento\Backend\Block\Store\Switcher\Form\Renderer\Fieldset\Element::class,
            $storeElement->getRenderer()
        );

        // Check store elements has expected values
        $storesList = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Store\Model\System\Store::class
        )->getStoreValuesForForm();
        $this->assertIsArray($storeElement->getValues());
        $this->assertNotEmpty($storeElement->getValues());
        $this->assertEquals($storesList, $storeElement->getValues());
    }

    /**
     * Test fields disabled status
     * @dataProvider fieldsStateDataProvider
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store general/single_store_mode/enabled 0
     */
    public function testReadonlyFields($urlRewrite, $fields)
    {
        $form = $this->_getFormInstance(['url_rewrite' => $urlRewrite]);
        foreach ($fields as $fieldKey => $expected) {
            $this->assertEquals($expected, $form->getElement($fieldKey)->getReadonly());
        }
    }

    /**
     * Data provider for checking fields state
     */
    public function fieldsStateDataProvider()
    {
        return [
            [
                new \Magento\Framework\DataObject(),
                [
                    'store_id' => false,
                ],
            ],
            [
                new \Magento\Framework\DataObject(['id' => 3, 'is_autogenerated' => true]),
                [
                    'store_id' => true,
                ]
            ]
        ];
    }
}
