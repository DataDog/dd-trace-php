<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Test class for \Magento\ImportExport\Block\Adminhtml\Export\Filter
 */
namespace Magento\ImportExport\Block\Adminhtml\Export;

class FilterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @magentoAppIsolation enabled
     */
    public function testGetDateFromToHtmlWithValue()
    {
        \Magento\TestFramework\Helper\Bootstrap::getInstance()
            ->loadArea(\Magento\Backend\App\Area\FrontNameResolver::AREA_CODE);
        \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(\Magento\Framework\View\DesignInterface::class)
            ->setDefaultDesignTheme();
        $block = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->create(\Magento\ImportExport\Block\Adminhtml\Export\Filter::class);
        $method = new \ReflectionMethod(
            \Magento\ImportExport\Block\Adminhtml\Export\Filter::class,
            '_getDateFromToHtmlWithValue'
        );
        $method->setAccessible(true);

        $arguments = [
            'data' => [
                'attribute_code' => 'date',
                'backend_type' => 'datetime',
                'frontend_input' => 'date',
                'frontend_label' => 'Date',
            ],
        ];
        $attribute = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Eav\Model\Entity\Attribute::class,
            $arguments
        );
        $html = $method->invoke($block, $attribute, null);
        $this->assertNotEmpty($html);

        $dateFormat = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\Stdlib\DateTime\TimezoneInterface::class
        )->getDateFormat(
            \IntlDateFormatter::SHORT
        );
        $pieces = array_filter(explode('<strong>', $html));
        foreach ($pieces as $piece) {
            $this->assertStringContainsString('dateFormat: "' . $dateFormat . '",', $piece);
        }
    }
}
