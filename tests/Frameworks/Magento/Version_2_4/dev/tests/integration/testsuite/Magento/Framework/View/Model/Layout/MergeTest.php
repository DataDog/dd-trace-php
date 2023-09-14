<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\View\Model\Layout;

use Magento\Framework\View\Layout\LayoutCacheKeyInterface;

class MergeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Fixture XML instruction(s) to be used in tests
     */
    const FIXTURE_LAYOUT_XML
        = '<block class="Magento\Framework\View\Element\Template" template="Magento_Framework::fixture.phtml"/>';

    /**
     * @var \Magento\Framework\View\Model\Layout\Merge
     */
    protected $model;

    /**
     * @var LayoutCacheKeyInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $layoutCacheKeyMock;

    protected function setUp(): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();

        /** @var $theme \Magento\Framework\View\Design\ThemeInterface */
        $theme = $objectManager->create(\Magento\Framework\View\Design\ThemeInterface::class);
        $theme->load(1);

        /** @var $layoutUpdate1 \Magento\Widget\Model\Layout\Update */
        $layoutUpdate1 = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Widget\Model\Layout\Update::class
        );
        $layoutUpdate1->setHandle('fixture_handle_one');
        $layoutUpdate1->setXml(
            '<body>
                <block class="Magento\Framework\View\Element\Template" 
                       template="Magento_Framework::fixture_template_one.phtml"/>
            </body>'
        );
        $layoutUpdate1->setHasDataChanges(true);
        $layoutUpdate1->save();
        $link1 = $objectManager->create(\Magento\Widget\Model\Layout\Link::class);
        $link1->setThemeId($theme->getId());
        $link1->setLayoutUpdateId($layoutUpdate1->getId());
        $link1->save();

        /** @var $layoutUpdate2 \Magento\Widget\Model\Layout\Update */
        $layoutUpdate2 = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Widget\Model\Layout\Update::class
        );
        $layoutUpdate2->setHandle('fixture_handle_two');
        $layoutUpdate2->setXml(
            '<body>
                <block class="Magento\Framework\View\Element\Template"
                       template="Magento_Framework::fixture_template_two.phtml"/>
            </body>'
        );
        $layoutUpdate2->setHasDataChanges(true);
        $layoutUpdate2->save($layoutUpdate2);
        $link2 = $objectManager->create(\Magento\Widget\Model\Layout\Link::class);
        $link2->setThemeId($theme->getId());
        $link2->setLayoutUpdateId($layoutUpdate2->getId());
        $link2->save();

        $this->layoutCacheKeyMock = $this->getMockForAbstractClass(LayoutCacheKeyInterface::class);
        $this->layoutCacheKeyMock->expects($this->any())
            ->method('getCacheKeys')
            ->willReturn([]);

        $this->model = $objectManager->create(
            \Magento\Framework\View\Model\Layout\Merge::class,
            [
                'theme' => $theme,
                'layoutCacheKey' => $this->layoutCacheKeyMock,
            ]
        );
    }

    public function testLoadDbApp()
    {
        $this->assertEmpty($this->model->getHandles());
        $this->assertEmpty($this->model->asString());
        $handles = ['fixture_handle_one', 'fixture_handle_two'];
        $this->model->load($handles);
        $expectedResult = '
            <root>
                <body>
                    <block class="Magento\Framework\View\Element\Template"
                           template="Magento_Framework::fixture_template_one.phtml"/>
                </body>
                <body>
                    <block class="Magento\Framework\View\Element\Template" 
                           template="Magento_Framework::fixture_template_two.phtml"/>
                </body>
            </root>
        ';
        $actualResult = '<root>' . $this->model->asString() . '</root>';
        $this->assertXmlStringEqualsXmlString($expectedResult, $actualResult);
    }
}
