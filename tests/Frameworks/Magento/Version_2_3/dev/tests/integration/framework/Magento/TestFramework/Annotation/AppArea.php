<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\TestFramework\Annotation;

class AppArea
{
    const ANNOTATION_NAME = 'magentoAppArea';

    /**
     * @var \Magento\TestFramework\Application
     */
    private $_application;

    /**
     * List of allowed areas.
     *
     * @var array
     */
    private $_allowedAreas = [
        \Magento\Framework\App\Area::AREA_GLOBAL,
        \Magento\Framework\App\Area::AREA_ADMINHTML,
        \Magento\Framework\App\Area::AREA_FRONTEND,
        \Magento\Framework\App\Area::AREA_WEBAPI_REST,
        \Magento\Framework\App\Area::AREA_WEBAPI_SOAP,
        \Magento\Framework\App\Area::AREA_CRONTAB,
        \Magento\Framework\App\Area::AREA_GRAPHQL
    ];

    /**
     * @param \Magento\TestFramework\Application $application
     */
    public function __construct(\Magento\TestFramework\Application $application)
    {
        $this->_application = $application;
    }

    /**
     * Get current application area
     *
     * @param array $annotations
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _getTestAppArea($annotations)
    {
        $area = isset(
            $annotations['method'][self::ANNOTATION_NAME]
        ) ? current(
            $annotations['method'][self::ANNOTATION_NAME]
        ) : (isset(
            $annotations['class'][self::ANNOTATION_NAME]
        ) ? current(
            $annotations['class'][self::ANNOTATION_NAME]
        ) : \Magento\TestFramework\Application::DEFAULT_APP_AREA);

        if (false == in_array($area, $this->_allowedAreas)) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __(
                    'Invalid "@magentoAppArea" annotation, can be "%1" only.',
                    implode('", "', $this->_allowedAreas)
                )
            );
        }

        return $area;
    }

    /**
     * Start test case event observer
     *
     * @param \PHPUnit\Framework\TestCase $test
     */
    public function startTest(\PHPUnit\Framework\TestCase $test)
    {
        $area = $this->_getTestAppArea($test->getAnnotations());
        if ($this->_application->getArea() !== $area) {
            $this->_application->reinitialize();

            if ($this->_application->getArea() !== $area) {
                $this->_application->loadArea($area);
            }
        }
    }
}
