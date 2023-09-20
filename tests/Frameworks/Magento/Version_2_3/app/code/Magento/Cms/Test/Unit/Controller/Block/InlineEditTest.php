<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Cms\Test\Unit\Controller\Block;

use Magento\Cms\Controller\Adminhtml\Block\InlineEdit;

class InlineEditTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Framework\App\RequestInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $request;

    /** @var \Magento\Cms\Model\Block|\PHPUnit\Framework\MockObject\MockObject */
    protected $cmsBlock;

    /** @var \Magento\Backend\App\Action\Context|\PHPUnit\Framework\MockObject\MockObject */
    protected $context;

    /** @var \Magento\Cms\Api\BlockRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $blockRepository;

    /** @var \Magento\Framework\Controller\Result\JsonFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $jsonFactory;

    /** @var \Magento\Framework\Controller\Result\Json|\PHPUnit\Framework\MockObject\MockObject */
    protected $resultJson;

    /** @var InlineEdit */
    protected $controller;

    protected function setUp(): void
    {
        $helper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->request = $this->getMockForAbstractClass(
            \Magento\Framework\App\RequestInterface::class,
            [],
            '',
            false
        );
        $this->cmsBlock = $this->createMock(\Magento\Cms\Model\Block::class);
        $this->context = $helper->getObject(
            \Magento\Backend\App\Action\Context::class,
            [
                'request' => $this->request
            ]
        );
        $this->blockRepository = $this->getMockForAbstractClass(
            \Magento\Cms\Api\BlockRepositoryInterface::class,
            [],
            '',
            false
        );
        $this->resultJson = $this->createMock(\Magento\Framework\Controller\Result\Json::class);
        $this->jsonFactory = $this->createPartialMock(
            \Magento\Framework\Controller\Result\JsonFactory::class,
            ['create']
        );
        $this->controller = new InlineEdit(
            $this->context,
            $this->blockRepository,
            $this->jsonFactory
        );
    }

    public function prepareMocksForTestExecute()
    {
        $postData = [
            1 => [
                'title' => 'Catalog Events Lister',
                'identifier' => 'Catalog Events Lister'
            ]
        ];

        $this->request->expects($this->at(0))
            ->method('getParam')
            ->with('isAjax')
            ->willReturn(true);
        $this->request->expects($this->at(1))
            ->method('getParam')
            ->with('items', [])
            ->willReturn($postData);
        $this->blockRepository->expects($this->once())
            ->method('getById')
            ->with(1)
            ->willReturn($this->cmsBlock);
        $this->cmsBlock->expects($this->atLeastOnce())
            ->method('getId')
            ->willReturn('1');
        $this->cmsBlock->expects($this->once())
            ->method('getData')
            ->willReturn([
                'identifier' => 'test-identifier'
            ]);
        $this->cmsBlock->expects($this->once())
            ->method('setData')
            ->with([
                'title' => 'Catalog Events Lister',
                'identifier' => 'Catalog Events Lister'
            ]);
        $this->jsonFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->resultJson);
    }

    public function testExecuteWithException()
    {
        $this->prepareMocksForTestExecute();
        $this->blockRepository->expects($this->once())
            ->method('save')
            ->with($this->cmsBlock)
            ->willThrowException(new \Exception(__('Exception')));
        $this->resultJson->expects($this->once())
            ->method('setData')
            ->with([
                'messages' => [
                    '[Block ID: 1] Exception'
                ],
                'error' => true
            ])
            ->willReturnSelf();

        $this->controller->execute();
    }

    public function testExecuteWithoutData()
    {
        $this->request->expects($this->at(0))
            ->method('getParam')
            ->with('isAjax')
            ->willReturn(true);
        $this->request->expects($this->at(1))
            ->method('getParam')
            ->with('items', [])
            ->willReturn([]);
        $this->jsonFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->resultJson);
        $this->resultJson->expects($this->once())
            ->method('setData')
            ->with([
                'messages' => [
                    'Please correct the data sent.'
                ],
                'error' => true
            ])
            ->willReturnSelf();

        $this->controller->execute();
    }
}
