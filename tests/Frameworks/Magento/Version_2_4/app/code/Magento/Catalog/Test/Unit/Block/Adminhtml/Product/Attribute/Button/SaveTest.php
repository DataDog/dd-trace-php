<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Block\Adminhtml\Product\Attribute\Button;

use Magento\Catalog\Block\Adminhtml\Product\Attribute\Button\Save;

class SaveTest extends GenericTest
{
    /**
     * {@inheritdoc}
     */
    protected function getModel()
    {
        return $this->objectManager->getObject(Save::class, [
            'context' => $this->contextMock,
            'registry' => $this->registryMock,
        ]);
    }

    public function testGetButtonData()
    {
        $this->assertEquals(
            [
                'label' => __('Save Attribute'),
                'class' => 'save primary',
                'data_attribute' => [
                    'mage-init' => ['button' => ['event' => 'save']],
                    'form-role' => 'save',
                ]
            ],
            $this->getModel()->getButtonData()
        );
    }
}
