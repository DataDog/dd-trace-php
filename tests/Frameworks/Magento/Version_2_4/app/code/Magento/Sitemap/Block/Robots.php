<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sitemap\Block;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Element\Context;
use Magento\Robots\Model\Config\Value;
use Magento\Sitemap\Helper\Data as SitemapHelper;
use Magento\Sitemap\Model\ResourceModel\Sitemap\CollectionFactory;
use Magento\Sitemap\Model\Sitemap;
use Magento\Sitemap\Model\SitemapConfigReader;
use Magento\Framework\App\ObjectManager;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\StoreResolver;

/**
 * Prepares sitemap links to add to the robots.txt file
 *
 * @api
 * @since 100.1.5
 */
class Robots extends AbstractBlock implements IdentityInterface
{
    /**
     * @var CollectionFactory
     */
    private $sitemapCollectionFactory;

    /**
     * @var SitemapHelper
     * @deprecated
     */
    private $sitemapHelper;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var SitemapConfigReader
     */
    private $sitemapConfigReader;

    /**
     * @param Context $context
     * @param StoreResolver $storeResolver
     * @param CollectionFactory $sitemapCollectionFactory
     * @param SitemapHelper $sitemapHelper
     * @param StoreManagerInterface $storeManager
     * @param array $data
     * @param SitemapConfigReader|null $sitemapConfigReader
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        Context $context,
        StoreResolver $storeResolver,
        CollectionFactory $sitemapCollectionFactory,
        SitemapHelper $sitemapHelper,
        StoreManagerInterface $storeManager,
        array $data = [],
        ?SitemapConfigReader $sitemapConfigReader = null
    ) {
        $this->sitemapCollectionFactory = $sitemapCollectionFactory;
        $this->sitemapHelper = $sitemapHelper;
        $this->storeManager = $storeManager;
        $this->sitemapConfigReader = $sitemapConfigReader
            ?: ObjectManager::getInstance()->get(SitemapConfigReader::class);

        parent::__construct($context, $data);
    }

    /**
     * Prepare sitemap links to add to the robots.txt file
     *
     * Collects sitemap links for all stores of given website.
     * Detects if sitemap file information is required to be added to robots.txt
     * and adds links for this sitemap files into result data.
     *
     * @return string
     * @since 100.1.5
     */
    protected function _toHtml()
    {
        $website = $this->storeManager->getWebsite();

        $storeIds = [];
        foreach ($website->getStoreIds() as $storeId) {
            if ((bool) $this->sitemapConfigReader->getEnableSubmissionRobots($storeId)) {
                $storeIds[] = (int) $storeId;
            }
        }

        $links = $storeIds ? $this->getSitemapLinks($storeIds) : [];

        return $links ? implode(PHP_EOL, $links) . PHP_EOL : '';
    }

    /**
     * Retrieve sitemap links for given store
     *
     * Gets the names of sitemap files that linked with given store,
     * and adds links for this sitemap files into result array.
     *
     * @param int[] $storeIds
     * @return array
     * @since 100.1.5
     */
    protected function getSitemapLinks(array $storeIds)
    {
        $collection = $this->sitemapCollectionFactory->create();
        $collection->addStoreFilter($storeIds);

        $sitemapLinks = [];
        /**
         * @var Sitemap $sitemap
         */
        foreach ($collection as $sitemap) {
            $sitemapUrl = $sitemap->getSitemapUrl($sitemap->getSitemapPath(), $sitemap->getSitemapFilename());
            $sitemapLinks[$sitemapUrl] = 'Sitemap: ' . $sitemapUrl;
        }

        return $sitemapLinks;
    }

    /**
     * Get unique page cache identities
     *
     * @return array
     * @since 100.1.5
     */
    public function getIdentities()
    {
        return [
            Value::CACHE_TAG . '_' . $this->storeManager->getDefaultStoreView()->getId(),
        ];
    }
}
