<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\PageCache\Test\Unit\Controller\Block;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EsiTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\App\Request\Http|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $requestMock;

    /**
     * @var \Magento\Framework\App\Response\Http|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $responseMock;

    /**
     * @var \Magento\Framework\App\View|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $viewMock;

    /**
     * @var \Magento\PageCache\Controller\Block
     */
    protected $action;

    /**
     * @var \Magento\Framework\View\Layout|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $layoutMock;

    /**
     * @var \Magento\Framework\View\Layout\LayoutCacheKeyInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $layoutCacheKeyMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\Translate\InlineInterface
     */
    protected $translateInline;

    /**
     * Set up before test
     */
    protected function setUp(): void
    {
        $this->layoutMock = $this->getMockBuilder(\Magento\Framework\View\Layout::class)
            ->disableOriginalConstructor()->getMock();

        $this->layoutCacheKeyMock = $this->getMockForAbstractClass(
            \Magento\Framework\View\Layout\LayoutCacheKeyInterface::class
        );

        $contextMock =
            $this->getMockBuilder(\Magento\Framework\App\Action\Context::class)
                ->disableOriginalConstructor()->getMock();

        $this->requestMock = $this->getMockBuilder(\Magento\Framework\App\Request\Http::class)
            ->disableOriginalConstructor()->getMock();
        $this->responseMock = $this->getMockBuilder(\Magento\Framework\App\Response\Http::class)
            ->disableOriginalConstructor()->getMock();
        $this->viewMock = $this->getMockBuilder(\Magento\Framework\App\View::class)
            ->disableOriginalConstructor()->getMock();

        $contextMock->expects($this->any())->method('getRequest')->willReturn($this->requestMock);
        $contextMock->expects($this->any())->method('getResponse')->willReturn($this->responseMock);
        $contextMock->expects($this->any())->method('getView')->willReturn($this->viewMock);

        $this->translateInline = $this->createMock(\Magento\Framework\Translate\InlineInterface::class);

        $helperObjectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->action = $helperObjectManager->getObject(
            \Magento\PageCache\Controller\Block\Esi::class,
            [
                'context' => $contextMock,
                'translateInline' => $this->translateInline,
                'jsonSerializer' => new \Magento\Framework\Serialize\Serializer\Json(),
                'base64jsonSerializer' => new \Magento\Framework\Serialize\Serializer\Base64Json(),
                'layoutCacheKey' => $this->layoutCacheKeyMock
            ]
        );
    }

    /**
     * @dataProvider executeDataProvider
     * @param string $blockClass
     * @param bool $shouldSetHeaders
     */
    public function testExecute($blockClass, $shouldSetHeaders)
    {
        $block = 'block';
        $handles = ['handle1', 'handle2'];
        $html = 'some-html';
        $mapData = [['blocks', '', json_encode([$block])], ['handles', '', base64_encode(json_encode($handles))]];

        $blockInstance1 = $this->createPartialMock($blockClass, ['toHtml']);

        $blockInstance1->expects($this->once())->method('toHtml')->willReturn($html);
        $blockInstance1->setTtl(360);

        $this->requestMock->expects($this->any())->method('getParam')->willReturnMap($mapData);

        $this->viewMock->expects($this->once())->method('loadLayout')->with($this->equalTo($handles));

        $this->viewMock->expects($this->once())->method('getLayout')->willReturn($this->layoutMock);

        $this->layoutMock->expects($this->never())
            ->method('getUpdate');
        $this->layoutCacheKeyMock->expects($this->atLeastOnce())
            ->method('addCacheKeys');

        $this->layoutMock->expects($this->once())
            ->method('getBlock')
            ->with($this->equalTo($block))
            ->willReturn($blockInstance1);

        if ($shouldSetHeaders) {
            $this->responseMock->expects($this->once())
                ->method('setHeader')
                ->with('X-Magento-Tags', implode(',', $blockInstance1->getIdentities()));
        } else {
            $this->responseMock->expects($this->never())
                ->method('setHeader');
        }

        $this->translateInline->expects($this->once())
            ->method('processResponseBody')
            ->with($html)
            ->willReturnSelf();

        $this->responseMock->expects($this->once())
            ->method('appendBody')
            ->with($this->equalTo($html));

        $this->action->execute();
    }

    /**
     * @return array
     */
    public function executeDataProvider()
    {
        return [
            [\Magento\PageCache\Test\Unit\Block\Controller\StubBlock::class, true],
            [\Magento\Framework\View\Element\AbstractBlock::class, false],
        ];
    }

    public function testExecuteBlockNotExists()
    {
        $handles = json_encode(['handle1', 'handle2']);
        $mapData = [
            ['blocks', '', null],
            ['handles', '', $handles],
        ];

        $this->requestMock->expects($this->any())->method('getParam')->willReturnMap($mapData);
        $this->viewMock->expects($this->never())->method('getLayout')->willReturn($this->layoutMock);

        $this->action->execute();
    }
}
