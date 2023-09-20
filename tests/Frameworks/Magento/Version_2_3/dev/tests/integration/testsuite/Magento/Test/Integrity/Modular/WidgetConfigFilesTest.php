<?php
/**
 * Tests that existing widget.xml files are valid to schema individually and merged.
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Test\Integrity\Modular;

use Magento\Framework\Component\ComponentRegistrar;

class WidgetConfigFilesTest extends \Magento\TestFramework\TestCase\AbstractConfigFiles
{
    /**
     * Returns the reader class name that will be instantiated via ObjectManager
     *
     * @return string reader class name
     */
    protected function _getReaderClassName()
    {
        return \Magento\Widget\Model\Config\Reader::class;
    }

    /**
     * Returns a string that represents the path to the config file
     *
     * @return string
     */
    protected function _getConfigFilePathGlob()
    {
        return 'etc/widget.xml';
    }

    /**
     * Returns an absolute path to the XSD file corresponding to the XML files specified in _getConfigFilePathGlob
     *
     * @return string
     */
    protected function _getXsdPath()
    {
        return $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'Magento_Widget')
            . '/etc/widget_file.xsd';
    }
}
