<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Css\PreProcessor\Adapter;

class CssInlinerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Css\PreProcessor\Adapter\CssInliner
     */
    private $model;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();

        $this->model = $this->objectManager->create(\Magento\Framework\Css\PreProcessor\Adapter\CssInliner::class);
    }

    /**
     * @param string $htmlFilePath
     * @param string $cssFilePath
     * @param string $cssExpected
     * @dataProvider getFilesDataProvider
     */
    public function testGetFiles($htmlFilePath, $cssFilePath, $cssExpected)
    {
        $html = file_get_contents($htmlFilePath);
        $css = file_get_contents($cssFilePath);
        $this->model->setCss($css);
        $this->model->setHtml($html);
        $result = $this->model->process();
        $this->assertStringContainsString($cssExpected, $result);
    }

    /**
     * @return array
     */
    public function getFilesDataProvider()
    {
        $fixtureDir = dirname(dirname(__DIR__));
        return [
            'noSpacesCss'=>[
                'resultHtml' => $fixtureDir . "/_files/css/test-input.html",
                'cssWithoutSpaces' => $fixtureDir . "/_files/css/test-css-no-spaces.css",
                'vertical-align: top; padding: 10px 10px 10px 0; width: 50%;'
            ],
            'withSpacesCss'=>[
                'resultHtml' => $fixtureDir . "/_files/css/test-input.html",
                'cssWithSpaces' => $fixtureDir . "/_files/css/test-css-with-spaces.css",
                'vertical-align: top; padding: 10px 10px 10px 0; width: 50%;'
            ],
        ];
    }

    /**
     * @param string $htmlFilePath
     * @param string $cssFilePath
     * @param string $cssExpected
     * @dataProvider getFilesDataProviderEmogrifier
     */
    public function testGetFilesEmogrifier($htmlFilePath, $cssFilePath, $cssExpected)
    {
        $emogrifier = new \Pelago\Emogrifier;

        $html = file_get_contents($htmlFilePath);
        $css = file_get_contents($cssFilePath);
        $emogrifier->setCss($css);
        $emogrifier->setHtml($html);
        $result = $emogrifier->emogrify();

        /**
         * This test was implemented for the issue which existed in the older version of Emogrifier.
         * Test was updated, as the library got updated as well.
         */
        $this->assertStringContainsString($cssExpected, $result);
    }

    /**
     * @return array
     */
    public function getFilesDataProviderEmogrifier()
    {
        $fixtureDir = dirname(dirname(__DIR__));
        return [
            'noSpacesCss'=>[
                'resultHtml' => $fixtureDir . "/_files/css/test-input.html",
                'cssWithoutSpaces' => $fixtureDir . "/_files/css/test-css-no-spaces.css",
                'vertical-align: top; padding: 10px 10px 10px 0; width: 50%;'
            ]
        ];
    }
}
