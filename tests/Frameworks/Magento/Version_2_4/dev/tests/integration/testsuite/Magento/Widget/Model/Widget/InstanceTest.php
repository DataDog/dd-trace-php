<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Widget\Model\Widget;

class InstanceTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Widget\Model\Widget\Instance
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_model = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Widget\Model\Widget\Instance::class
        );
    }

    public function testSetGetType()
    {
        $this->assertEmpty($this->_model->getType());
        $this->assertSame('test', $this->_model->setType('test')->getType());
        $this->assertSame('test', $this->_model->getInstanceType());
    }

    public function testSetThemeId()
    {
        \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(\Magento\Framework\App\State::class)
            ->setAreaCode('frontend');
        $theme = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\View\DesignInterface::class
        )->setDefaultDesignTheme()->getDesignTheme();
        $this->_model->setThemeId($theme->getId());

        $this->assertEquals($theme->getId(), $this->_model->getThemeId());
    }

    /**
     * @return \Magento\Widget\Model\Widget\Instance
     */
    public function testGetWidgetConfigAsArray()
    {
        $config = $this->_model->setType(\Magento\Catalog\Block\Product\Widget\NewWidget::class)
            ->getWidgetConfigAsArray();
        $this->assertIsArray($config);
        $element = null;
        if (isset(
            $config['parameters']
        ) && isset(
            $config['parameters']['template']
        ) && isset(
            $config['parameters']['template']['values']
        ) && isset(
            $config['parameters']['template']['values']['list']
        )
        ) {
            $element = $config['parameters']['template']['values']['list'];
        }
        $expected = [
            'value' => 'product/widget/new/content/new_list.phtml',
            'label' => 'New Products List Template',
        ];
        $this->assertNotNull($element);
        $this->assertEquals($expected, $element);

        return $this->_model;
    }

    /**
     * @return \Magento\Widget\Model\Widget\Instance
     */
    public function testGetWidgetSupportedContainers()
    {
        $this->_model->setType(\Magento\Catalog\Block\Product\Widget\NewWidget::class);
        $containers = $this->_model->getWidgetSupportedContainers();
        $this->assertIsArray($containers);
        $this->assertContains('sidebar.main', $containers);
        $this->assertContains('content', $containers);
        $this->assertContains('sidebar.additional', $containers);

        return $this->_model;
    }

    /**
     * @param \Magento\Widget\Model\Widget\Instance $model
     * @depends testGetWidgetSupportedContainers
     */
    public function testGetWidgetSupportedTemplatesByContainer($model)
    {
        $templates = $model->getWidgetSupportedTemplatesByContainer('content');
        $this->assertNotEmpty($templates);
        $this->assertIsArray($templates);
        foreach ($templates as $row) {
            $this->assertArrayHasKey('value', $row);
            $this->assertArrayHasKey('label', $row);
        }
    }

    /**
     * @covers  \Magento\Widget\Model\Widget\Instance::generateLayoutUpdateXml()
     * @covers  \Magento\Widget\Model\Widget\Instance::getWidgetParameters()
     * @param \Magento\Widget\Model\Widget\Instance $model
     * @depends testGetWidgetConfigAsArray
     */
    public function testGenerateLayoutUpdateXml(\Magento\Widget\Model\Widget\Instance $model)
    {
        $params = [
            'display_mode' => 'fixed',
            'types' => ['type_1', 'type_2'],
            'conditions' => [
                '1' => [
                    'type' => \Magento\CatalogWidget\Model\Rule\Condition\Combine::class,
                    'aggregator' => 'all',
                    'value' => '1',
                    'new_child' => '',
                ],
                '1--1' => [
                    'type' => \Magento\CatalogWidget\Model\Rule\Condition\Product::class,
                    'attribute' => 'attribute_set_id',
                    'value' => '4',
                    'operator' => '==',
                ],
            ],
        ];
        $model->setData('widget_parameters', $params);
        $this->assertEquals('', $model->generateLayoutUpdateXml('content'));
        $model->setId('test_id')->setPackageTheme('Magento/luma');
        $result = $model->generateLayoutUpdateXml('content');
        $this->assertStringContainsString('<body><referenceContainer name="content">', $result);
        $this->assertStringContainsString('<block class="' . $model->getType() . '"', $result);
        $this->assertEquals(count($params), substr_count($result, '<action method="setData">'));
        $this->assertStringContainsString('<argument name="name" xsi:type="string">display_mode</argument>', $result);
        $this->assertStringContainsString('<argument name="value" xsi:type="string">fixed</argument>', $result);
        $this->assertStringContainsString('<argument name="name" xsi:type="string">types</argument>', $result);
        $this->assertStringContainsString('<argument name="value" xsi:type="string">type_1,type_2</argument>', $result);
        $this->assertStringContainsString(
            '<argument name="name" xsi:type="string">conditions_encoded</argument>',
            $result
        );
        $this->assertStringContainsString('`Magento||CatalogWidget||Model||Rule||Condition||Combine`', $result);
        $this->assertStringContainsString('`Magento||CatalogWidget||Model||Rule||Condition||Product`', $result);
    }

    /**
     * @covers \Magento\Widget\Model\Widget\Instance::beforeSave()
     * @magentoDataFixture Magento/Widget/_files/new_widget.php
     * @dataProvider beforeSaveDataProvider
     * @param array $expected
     */
    public function testBeforeSave(array $expected)
    {
        /** @var \Magento\Widget\Model\ResourceModel\Widget\Instance $resourceModel */
        $resourceModel = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(\Magento\Widget\Model\ResourceModel\Widget\Instance::class);
        $resourceModel->load($this->_model, 'Magento\Widget\NewSampleWidget', 'instance_type');

        $this->assertSame($expected, $this->_model->getWidgetParameters());
    }

    /**
     * @return array
     */
    public function beforeSaveDataProvider()
    {
        return [
            # Variation 1
            [
                ['block_id' => '2']
            ]
        ];
    }

    /**
     * @param Instance $model
     * @depends testGetWidgetConfigAsArray
     */
    public function testGenerateLayoutUpdateXmlWithInvalidParamName(\Magento\Widget\Model\Widget\Instance $model)
    {
        $params = [
            'block_id' => '2',
            'block_id</argument><argument name="value" xsi:type="string">2</argument></action></block><block'
            . ' class="Magento\Cms\Block\Widget\Block" name="dfgdfgdfg" template="widget/static_block/default.phtml">'
            . '<action method="setData"><argument name="name" xsi:type="string">' => 'some_value',
        ];
        $this->expectException('\Magento\Framework\Exception\LocalizedException');
        $this->expectExceptionMessage('Layout update is invalid');
        $model->setData('widget_parameters', $params);
        $model->generateLayoutUpdateXml('content');
    }
}
