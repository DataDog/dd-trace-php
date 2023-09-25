<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Indexer\Test\Unit\Controller\Adminhtml\Indexer;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MassOnTheFlyTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Indexer\Controller\Adminhtml\Indexer\MassOnTheFly
     */
    protected $model;

    /**
     * @var /Magento\Backend\App\Action\Context
     */
    protected $contextMock;

    /**
     * @var \Magento\Framework\App\ViewInterface
     */
    protected $view;

    /**
     * @var \Magento\Framework\View\Result\Page
     */
    protected $page;

    /**
     * @var \Magento\Framework\View\Page\Config
     */
    protected $config;

    /**
     * @var \Magento\Framework\View\Page\Title
     */
    protected $title;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \Magento\Framework\Indexer\IndexerRegistry
     */
    protected $indexReg;

    /**
     * @return \Magento\Framework\App\ResponseInterface
     */
    protected $response;

    /**
     * @var \Magento\Framework\App\ActionFlag
     */
    protected $actionFlag;

    /**
     * @var \Magento\Backend\Helper\Data
     */
    protected $helper;

    /**
     * @var  \Magento\Backend\Model\Session
     */
    protected $session;

    /**
     * Set up test
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        $this->contextMock = $this->createPartialMock(\Magento\Backend\App\Action\Context::class, [
                'getAuthorization',
                'getSession',
                'getActionFlag',
                'getAuth',
                'getView',
                'getHelper',
                'getBackendUrl',
                'getFormKeyValidator',
                'getLocaleResolver',
                'getCanUseBaseUrl',
                'getRequest',
                'getResponse',
                'getObjectManager',
                'getMessageManager'
            ]);

        $this->response = $this->createPartialMock(
            \Magento\Framework\App\ResponseInterface::class,
            ['setRedirect', 'sendResponse']
        );

        $this->view = $this->createPartialMock(
            \Magento\Framework\App\ViewInterface::class,
            [
                'loadLayout',
                'getPage',
                'getConfig',
                'getTitle',
                'renderLayout',
                'loadLayoutUpdates',
                'getDefaultLayoutHandle',
                'addPageLayoutHandles',
                'generateLayoutBlocks',
                'generateLayoutXml',
                'getLayout',
                'addActionLayoutHandles',
                'setIsLayoutLoaded',
                'isLayoutLoaded'
            ]
        );

        $this->session = $this->createPartialMock(\Magento\Backend\Model\Session::class, ['setIsUrlNotice']);
        $this->session->expects($this->any())->method('setIsUrlNotice')->willReturn($this->objectManager);
        $this->actionFlag = $this->createPartialMock(\Magento\Framework\App\ActionFlag::class, ['get']);
        $this->actionFlag->expects($this->any())->method("get")->willReturn($this->objectManager);
        $this->objectManager = $this->createPartialMock(
            \Magento\Framework\TestFramework\Unit\Helper\ObjectManager::class,
            ['get']
        );
        $this->request = $this->getMockForAbstractClass(
            \Magento\Framework\App\RequestInterface::class,
            ['getParam', 'getRequest'],
            '',
            false
        );

        $this->response->expects($this->any())->method("setRedirect")->willReturn(1);
        $this->page = $this->createMock(\Magento\Framework\View\Result\Page::class);
        $this->config = $this->createMock(\Magento\Framework\View\Result\Page::class);
        $this->title = $this->createMock(\Magento\Framework\View\Page\Title::class);
        $this->messageManager = $this->getMockForAbstractClass(
            \Magento\Framework\Message\ManagerInterface::class,
            ['addError', 'addSuccess'],
            '',
            false
        );

        $this->indexReg = $this->createPartialMock(
            \Magento\Framework\Indexer\IndexerRegistry::class,
            ['get', 'setScheduled']
        );
        $this->helper = $this->createPartialMock(\Magento\Backend\Helper\Data::class, ['getUrl']);
        $this->contextMock->expects($this->any())->method("getObjectManager")->willReturn($this->objectManager);
        $this->contextMock->expects($this->any())->method("getRequest")->willReturn($this->request);
        $this->contextMock->expects($this->any())->method("getResponse")->willReturn($this->response);
        $this->contextMock->expects($this->any())->method("getMessageManager")->willReturn($this->messageManager);
        $this->contextMock->expects($this->any())->method("getSession")->willReturn($this->session);
        $this->contextMock->expects($this->any())->method("getActionFlag")->willReturn($this->actionFlag);
        $this->contextMock->expects($this->any())->method("getHelper")->willReturn($this->helper);
    }

    /**
     * @param array $indexerIds
     * @param \Exception $exception
     * @param array $expectsExceptionValues
     * @dataProvider executeDataProvider
     */
    public function testExecute($indexerIds, $exception, $expectsExceptionValues)
    {
        $this->model = new \Magento\Indexer\Controller\Adminhtml\Indexer\MassOnTheFly($this->contextMock);
        $this->request->expects($this->any())
            ->method('getParam')->with('indexer_ids')
            ->willReturn($indexerIds);

        if (!is_array($indexerIds)) {
            $this->messageManager->expects($this->once())
                ->method('addError')->with(__('Please select indexers.'))
                ->willReturn(1);
        } else {
            $this->objectManager->expects($this->any())
                ->method('get')->with(\Magento\Framework\Indexer\IndexerRegistry::class)
                ->willReturn($this->indexReg);
            $indexerInterface = $this->getMockForAbstractClass(
                \Magento\Framework\Indexer\IndexerInterface::class,
                ['setScheduled'],
                '',
                false
            );
            $this->indexReg->expects($this->any())
                ->method('get')->with(1)
                ->willReturn($indexerInterface);

            if ($exception !== null) {
                $indexerInterface->expects($this->any())
                    ->method('setScheduled')->with(false)->will($this->throwException($exception));
            } else {
                $indexerInterface->expects($this->any())
                    ->method('setScheduled')->with(false)->willReturn(1);
            }

            $this->messageManager->expects($this->any())->method('addSuccess')->willReturn(1);

            if ($exception !== null) {
                $this->messageManager->expects($this->exactly($expectsExceptionValues[2]))
                    ->method('addError')
                    ->with($exception->getMessage());
                $this->messageManager->expects($this->exactly($expectsExceptionValues[1]))
                    ->method('addException')
                    ->with($exception, "We couldn't change indexer(s)' mode because of an error.");
            }
        }

        $this->helper->expects($this->any())->method("getUrl")->willReturn("magento.com");
        $this->response->expects($this->any())->method("setRedirect")->willReturn(1);

        $result = $this->model->execute();
        $this->assertNull($result);
    }

    /**
     * @return array
     */
    public function executeDataProvider()
    {
        return [
            'set1' => [
                'idexers' => 1,
                "exception" => null,
                "expectsValues" => [0, 0, 0]
            ],
            'set2' => [
                'idexers' => [1],
                "exception" => null,
                "expectsException" => [1, 0, 0]
            ],
            'set3' => [
                'idexers' => [1],
                "exception" => new \Magento\Framework\Exception\LocalizedException(__('Test Phrase')),
                "expectsException" => [0, 0, 1]
            ],
            'set4' => [
                'idexers' => [1],
                "exception" => new \Exception(),
                "expectsException" => [0, 1, 0]
            ]
        ];
    }
}
