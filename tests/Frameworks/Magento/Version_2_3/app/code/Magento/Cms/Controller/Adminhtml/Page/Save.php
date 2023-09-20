<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Cms\Controller\Adminhtml\Page;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Backend\App\Action;
use Magento\Cms\Model\Page;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Save CMS page action.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Save extends \Magento\Backend\App\Action implements HttpPostActionInterface
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_Cms::save';

    /**
     * @var PostDataProcessor
     */
    protected $dataProcessor;

    /**
     * @var DataPersistorInterface
     */
    protected $dataPersistor;

    /**
     * @var \Magento\Cms\Model\PageFactory
     */
    private $pageFactory;

    /**
     * @var \Magento\Cms\Api\PageRepositoryInterface
     */
    private $pageRepository;

    /**
     * @param Action\Context $context
     * @param PostDataProcessor $dataProcessor
     * @param DataPersistorInterface $dataPersistor
     * @param \Magento\Cms\Model\PageFactory|null $pageFactory
     * @param \Magento\Cms\Api\PageRepositoryInterface|null $pageRepository
     */
    public function __construct(
        Action\Context $context,
        PostDataProcessor $dataProcessor,
        DataPersistorInterface $dataPersistor,
        \Magento\Cms\Model\PageFactory $pageFactory = null,
        \Magento\Cms\Api\PageRepositoryInterface $pageRepository = null
    ) {
        $this->dataProcessor = $dataProcessor;
        $this->dataPersistor = $dataPersistor;
        $this->pageFactory = $pageFactory ?: ObjectManager::getInstance()->get(\Magento\Cms\Model\PageFactory::class);
        $this->pageRepository = $pageRepository
            ?: ObjectManager::getInstance()->get(\Magento\Cms\Api\PageRepositoryInterface::class);
        parent::__construct($context);
    }

    /**
     * Save action
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $data = $this->getRequest()->getPostValue();
        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        if ($data) {
            $data = $this->dataProcessor->filter($data);
            if (isset($data['is_active']) && $data['is_active'] === 'true') {
                $data['is_active'] = Page::STATUS_ENABLED;
            }
            if (empty($data['page_id'])) {
                $data['page_id'] = null;
            }

            /** @var \Magento\Cms\Model\Page $model */
            $model = $this->pageFactory->create();

            $id = $this->getRequest()->getParam('page_id');
            if ($id) {
                try {
                    $model = $this->pageRepository->getById($id);
                } catch (LocalizedException $e) {
                    $this->messageManager->addErrorMessage(__('This page no longer exists.'));
                    return $resultRedirect->setPath('*/*/');
                }
            }

            $data['layout_update_xml'] = $model->getLayoutUpdateXml();
            $data['custom_layout_update_xml'] = $model->getCustomLayoutUpdateXml();
            $model->setData($data);

            try {
                $this->_eventManager->dispatch(
                    'cms_page_prepare_save',
                    ['page' => $model, 'request' => $this->getRequest()]
                );

                $this->pageRepository->save($model);
                $this->messageManager->addSuccessMessage(__('You saved the page.'));
                return $this->processResultRedirect($model, $resultRedirect, $data);
            } catch (LocalizedException $e) {
                $this->messageManager->addExceptionMessage($e->getPrevious() ?: $e);
            } catch (\Throwable $e) {
                $this->messageManager->addExceptionMessage($e, __('Something went wrong while saving the page.'));
            }

            $this->dataPersistor->set('cms_page', $data);
            return $resultRedirect->setPath('*/*/edit', ['page_id' => $this->getRequest()->getParam('page_id')]);
        }
        return $resultRedirect->setPath('*/*/');
    }

    /**
     * Process result redirect
     *
     * @param \Magento\Cms\Api\Data\PageInterface $model
     * @param \Magento\Backend\Model\View\Result\Redirect $resultRedirect
     * @param array $data
     * @return \Magento\Backend\Model\View\Result\Redirect
     * @throws LocalizedException
     */
    private function processResultRedirect($model, $resultRedirect, $data)
    {
        if ($this->getRequest()->getParam('back', false) === 'duplicate') {
            $newPage = $this->pageFactory->create(['data' => $data]);
            $newPage->setId(null);
            $identifier = $model->getIdentifier() . '-' . uniqid();
            $newPage->setIdentifier($identifier);
            $newPage->setIsActive(false);
            $this->pageRepository->save($newPage);
            $this->messageManager->addSuccessMessage(__('You duplicated the page.'));
            return $resultRedirect->setPath(
                '*/*/edit',
                [
                    'page_id' => $newPage->getId(),
                    '_current' => true
                ]
            );
        }
        $this->dataPersistor->clear('cms_page');
        if ($this->getRequest()->getParam('back')) {
            return $resultRedirect->setPath('*/*/edit', ['page_id' => $model->getId(), '_current' => true]);
        }
        return $resultRedirect->setPath('*/*/');
    }
}
