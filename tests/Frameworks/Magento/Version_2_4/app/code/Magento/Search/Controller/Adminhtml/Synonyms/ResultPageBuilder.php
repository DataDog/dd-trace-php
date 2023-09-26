<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Search\Controller\Adminhtml\Synonyms;

use Magento\Framework\Search\EngineResolverInterface;
use Magento\Framework\Search\SearchEngine\ConfigInterface;

/**
 * Result page builder class
 *
 */
class ResultPageBuilder
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory $resultPageFactory
     */
    protected $resultPageFactory;

    /**
     * @var EngineResolverInterface $engineResolver
     */
    protected $engineResolver;

    /**
     * @var ConfigInterface $searchFeatureConfig
     */
    protected $searchFeatureConfig;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * Constructor
     *
     * @param \Magento\Framework\View\Result\PageFactory $resultPageFactory
     * @param EngineResolverInterface $engineResolver
     * @param ConfigInterface $searchFeatureConfig
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     */
    public function __construct(
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        EngineResolverInterface $engineResolver,
        ConfigInterface $searchFeatureConfig,
        \Magento\Framework\Message\ManagerInterface $messageManager
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->engineResolver = $engineResolver;
        $this->searchFeatureConfig = $searchFeatureConfig;
        $this->messageManager = $messageManager;
    }

    /**
     * Build the initial page layout, menu and breadcrumb trail
     *
     * @return \Magento\Backend\Model\View\Result\Page
     */
    public function build()
    {
        $this->checkSearchEngineSupport();
        /** @var \Magento\Backend\Model\View\Result\Page  $resultPage **/
        $resultPage = $this->resultPageFactory->create();

        // Make it active on menu and set breadcrumb trail
        $resultPage->setActiveMenu('Magento_Search::search_synonyms');
        $resultPage->addBreadcrumb(__('Marketing'), __('Marketing'));
        $resultPage->addBreadcrumb(__('Search Synonyms'), __('Search Synonyms'));
        return $resultPage;
    }

    /**
     * Checks if 'synonyms' feature is supported by configured search engine. If not supported displays a notice
     *
     * @return void
     */
    protected function checkSearchEngineSupport()
    {
        // Display a notice if search engine configuration does not support synonyms
        $searchEngine = $this->engineResolver->getCurrentSearchEngine();
        if (!$this->searchFeatureConfig
            ->isFeatureSupported(
                ConfigInterface::SEARCH_ENGINE_FEATURE_SYNONYMS,
                $searchEngine
            )
        ) {
            $this->messageManager
                ->addNoticeMessage(
                    __(
                        'Search synonyms are not supported by the %1 search engine. '
                        . 'Any synonyms you enter won\'t be used.',
                        $searchEngine
                    )
                );
        }
    }
}
