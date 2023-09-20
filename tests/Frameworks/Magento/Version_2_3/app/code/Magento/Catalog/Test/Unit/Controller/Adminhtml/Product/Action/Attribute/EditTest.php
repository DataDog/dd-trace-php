<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Controller\Adminhtml\Product\Action\Attribute;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EditTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Catalog\Controller\Adminhtml\Product\Action\Attribute\Save */
    private $object;

    /** @var \Magento\Catalog\Helper\Product\Edit\Action\Attribute|\PHPUnit\Framework\MockObject\MockObject */
    private $attributeHelper;

    /** @var \Magento\Backend\Model\View\Result\RedirectFactory|\PHPUnit\Framework\MockObject\MockObject */
    private $resultRedirectFactory;

    /** @var \Magento\Ui\Component\MassAction\Filter|\PHPUnit\Framework\MockObject\MockObject */
    private $filter;

    /** @var \Magento\Backend\App\Action\Context|\PHPUnit\Framework\MockObject\MockObject */
    private $context;

    /** @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory|\PHPUnit\Framework\MockObject\MockObject */
    private $collectionFactory;

    /** @var \Magento\Framework\View\Result\Page|\PHPUnit\Framework\MockObject\MockObject */
    private $resultPage;

    /** @var \Magento\Framework\App\Request\Http|\PHPUnit\Framework\MockObject\MockObject */
    private $request;

    protected function setUp(): void
    {
        $this->attributeHelper = $this->getMockBuilder(\Magento\Catalog\Helper\Product\Edit\Action\Attribute::class)
            ->setMethods(['getProductIds', 'setProductIds'])
            ->disableOriginalConstructor()->getMock();

        $this->resultRedirectFactory = $this->getMockBuilder(\Magento\Backend\Model\View\Result\RedirectFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $this->filter = $this->getMockBuilder(\Magento\Ui\Component\MassAction\Filter::class)
            ->setMethods(['getCollection'])->disableOriginalConstructor()->getMock();

        $this->collectionFactory = $this->getMockBuilder(
            \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory::class
        )->setMethods(['create'])->disableOriginalConstructor()->getMock();

        $this->resultPage = $this->getMockBuilder(\Magento\Framework\View\Result\Page::class)
            ->setMethods(['getConfig'])->disableOriginalConstructor()->getMock();

        $resultPageFactory = $this->getMockBuilder(\Magento\Framework\View\Result\PageFactory::class)
            ->setMethods(['create'])->disableOriginalConstructor()->getMock();
        $resultPageFactory->expects($this->any())->method('create')->willReturn($this->resultPage);

        $this->prepareContext();

        $this->object = (new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this))->getObject(
            \Magento\Catalog\Controller\Adminhtml\Product\Action\Attribute\Edit::class,
            [
                'context' => $this->context,
                'attributeHelper' => $this->attributeHelper,
                'filter' => $this->filter,
                'resultPageFactory' => $resultPageFactory,
                'collectionFactory' => $this->collectionFactory
            ]
        );
    }

    private function prepareContext()
    {
        $this->request = $this->getMockBuilder(\Magento\Framework\App\Request\Http::class)
            ->setMethods(['getParam', 'getParams', 'setParams'])
            ->disableOriginalConstructor()->getMock();

        $objectManager = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $product = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->setMethods(['isProductsHasSku'])
            ->disableOriginalConstructor()->getMock();
        $product->expects($this->any())->method('isProductsHasSku')
            ->with([1, 2, 3])
            ->willReturn(true);
        $objectManager->expects($this->any())
            ->method('create')
            ->with(\Magento\Catalog\Model\Product::class)
            ->willReturn($product);
        $messageManager = $this->getMockBuilder(\Magento\Framework\Message\ManagerInterface::class)
            ->setMethods([])
            ->disableOriginalConstructor()->getMock();
        $messageManager->expects($this->any())->method('addErrorMessage')->willReturn(true);
        $this->context = $this->getMockBuilder(\Magento\Backend\App\Action\Context::class)
            ->setMethods(['getRequest', 'getObjectManager', 'getMessageManager', 'getResultRedirectFactory'])
            ->disableOriginalConstructor()->getMock();
        $this->context->expects($this->any())->method('getRequest')->willReturn($this->request);
        $this->context->expects($this->any())->method('getObjectManager')->willReturn($objectManager);
        $this->context->expects($this->any())->method('getMessageManager')->willReturn($messageManager);
        $this->context->expects($this->any())->method('getResultRedirectFactory')
            ->willReturn($this->resultRedirectFactory);
    }

    public function testExecutePageRequested()
    {
        $this->request->expects($this->any())->method('getParam')->with('filters')->willReturn(['placeholder' => true]);
        $this->request->expects($this->any())->method('getParams')->willReturn(
            [
                'namespace' => 'product_listing',
                'exclude' => true,
                'filters' => ['placeholder' => true]
            ]
        );

        $this->attributeHelper->expects($this->any())->method('getProductIds')->willReturn([1, 2, 3]);
        $this->attributeHelper->expects($this->any())->method('setProductIds')->with([1, 2, 3]);

        $collection = $this->getMockBuilder(\Magento\Catalog\Model\ResourceModel\Product\Collection::class)
            ->setMethods(['getAllIds'])
            ->disableOriginalConstructor()->getMock();
        $collection->expects($this->any())->method('getAllIds')->willReturn([1, 2, 3]);
        $this->filter->expects($this->any())->method('getCollection')->with($collection)->willReturn($collection);
        $this->collectionFactory->expects($this->any())->method('create')->willReturn($collection);

        $title = $this->getMockBuilder(\Magento\Framework\View\Page\Title::class)
            ->setMethods(['prepend'])
            ->disableOriginalConstructor()->getMock();
        $config = $this->getMockBuilder(\Magento\Framework\View\Page\Config::class)
            ->setMethods(['getTitle'])
            ->disableOriginalConstructor()->getMock();
        $config->expects($this->any())->method('getTitle')->willReturn($title);
        $this->resultPage->expects($this->any())->method('getConfig')->willReturn($config);

        $this->assertSame($this->resultPage, $this->object->execute());
    }

    public function testExecutePageReload()
    {
        $this->request->expects($this->any())->method('getParam')->with('filters')->willReturn(null);
        $this->request->expects($this->any())->method('getParams')->willReturn([]);

        $this->attributeHelper->expects($this->any())->method('getProductIds')->willReturn([1, 2, 3]);
        $this->attributeHelper->expects($this->any())->method('setProductIds')->with([1, 2, 3]);

        $title = $this->getMockBuilder(\Magento\Framework\View\Page\Title::class)
            ->setMethods(['prepend'])
            ->disableOriginalConstructor()->getMock();
        $config = $this->getMockBuilder(\Magento\Framework\View\Page\Config::class)
            ->setMethods(['getTitle'])
            ->disableOriginalConstructor()->getMock();
        $config->expects($this->any())->method('getTitle')->willReturn($title);
        $this->resultPage->expects($this->any())->method('getConfig')->willReturn($config);

        $this->assertSame($this->resultPage, $this->object->execute());
    }

    public function testExecutePageDirectAccess()
    {
        $this->request->expects($this->any())->method('getParam')->with('filters')->willReturn(null);
        $this->request->expects($this->any())->method('getParams')->willReturn([]);
        $this->attributeHelper->expects($this->any())->method('getProductIds')->willReturn(null);

        $resultRedirect = $this->getMockBuilder(\Magento\Backend\Model\View\Result\Redirect::class)
            ->setMethods(['setPath'])
            ->disableOriginalConstructor()
            ->getMock();
        $resultRedirect->expects($this->any())->method('setPath')
            ->with('catalog/product/', ['_current' => true])
            ->willReturnSelf();
        $this->resultRedirectFactory->expects($this->any())
            ->method('create')
            ->willReturn($resultRedirect);

        $this->assertSame($resultRedirect, $this->object->execute());
    }
}
