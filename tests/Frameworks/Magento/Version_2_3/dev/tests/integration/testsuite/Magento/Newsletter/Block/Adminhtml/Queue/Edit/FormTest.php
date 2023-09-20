<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Newsletter\Block\Adminhtml\Queue\Edit;

/**
 * Test class for \Magento\Newsletter\Block\Adminhtml\Queue\Edit\Form
 * @magentoAppArea adminhtml
 */
class FormTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @magentoAppIsolation enabled
     */
    public function testPrepareForm()
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $queue = $objectManager->get(\Magento\Newsletter\Model\Queue::class);
        /** @var \Magento\Framework\Registry $registry */
        $registry = $objectManager->get(\Magento\Framework\Registry::class);
        $registry->register('current_queue', $queue);

        $objectManager->get(
            \Magento\Framework\View\DesignInterface::class
        )->setArea(
            \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE
        )->setDefaultDesignTheme();
        $objectManager->get(
            \Magento\Framework\Config\ScopeInterface::class
        )->setCurrentScope(
            \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE
        );
        $block = $objectManager->create(
            \Magento\Newsletter\Block\Adminhtml\Queue\Edit\Form::class,
            ['registry' => $registry]
        );
        $prepareFormMethod = new \ReflectionMethod(
            \Magento\Newsletter\Block\Adminhtml\Queue\Edit\Form::class,
            '_prepareForm'
        );
        $prepareFormMethod->setAccessible(true);

        $statuses = [
            \Magento\Newsletter\Model\Queue::STATUS_NEVER,
            \Magento\Newsletter\Model\Queue::STATUS_PAUSE,
        ];
        foreach ($statuses as $status) {
            $queue->setQueueStatus($status);
            $prepareFormMethod->invoke($block);
            $element = $block->getForm()->getElement('date');
            $this->assertNotNull($element);
            $this->assertNotEmpty($element->getTimeFormat());
            $this->assertNotEmpty($element->getDateFormat());
        }
    }
}
