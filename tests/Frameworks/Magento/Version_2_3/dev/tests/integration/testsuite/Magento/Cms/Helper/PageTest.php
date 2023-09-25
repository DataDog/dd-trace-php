<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Cms\Helper;

use Magento\Customer\Model\Context;

/**
 * @magentoAppArea frontend
 */
class PageTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Cms/_files/pages.php
     */
    public function testRenderPage()
    {
        /** @var $objectManager \Magento\TestFramework\ObjectManager */
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $httpContext = $objectManager->get(\Magento\Framework\App\Http\Context::class);
        $httpContext->setValue(Context::CONTEXT_AUTH, false, false);
        $objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
        $arguments = [
            'request' => $objectManager->get(\Magento\TestFramework\Request::class),
            'response' => $objectManager->get(\Magento\TestFramework\Response::class),
        ];
        $context = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Framework\App\Action\Context::class,
            $arguments
        );
        $page = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(\Magento\Cms\Model\Page::class);
        $page->load('page_design_blank', 'identifier');
        // fixture
        /** @var $pageHelper \Magento\Cms\Helper\Page */
        $pageHelper = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(\Magento\Cms\Helper\Page::class);
        $result = $pageHelper->prepareResultPage(
            \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
                \Magento\Framework\App\Test\Unit\Action\Stub\ActionStub::class,
                ['context' => $context]
            ),
            $page->getId()
        );
        $design = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\View\DesignInterface::class
        );
        $this->assertEquals('Magento/blank', $design->getDesignTheme()->getThemePath());
        $this->assertInstanceOf(\Magento\Framework\View\Result\Page::class, $result);
    }
}
