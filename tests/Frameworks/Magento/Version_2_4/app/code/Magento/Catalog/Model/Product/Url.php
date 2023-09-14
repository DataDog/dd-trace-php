<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model\Product;

use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Product Url model
 *
 * @api
 * @since 100.0.2
 */
class Url extends \Magento\Framework\DataObject
{
    /**
     * URL instance
     *
     * @var \Magento\Framework\UrlFactory
     */
    protected $urlFactory;

    /**
     * @var \Magento\Framework\Filter\FilterManager
     */
    protected $filter;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\Framework\Session\SidResolverInterface
     */
    protected $sidResolver;

    /**
     * @var \Magento\UrlRewrite\Model\UrlFinderInterface
     */
    protected $urlFinder;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param \Magento\Framework\UrlFactory $urlFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Filter\FilterManager $filter
     * @param \Magento\Framework\Session\SidResolverInterface $sidResolver
     * @param UrlFinderInterface $urlFinder
     * @param array $data
     * @param ScopeConfigInterface|null $scopeConfig
     */
    public function __construct(
        \Magento\Framework\UrlFactory $urlFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Filter\FilterManager $filter,
        \Magento\Framework\Session\SidResolverInterface $sidResolver,
        UrlFinderInterface $urlFinder,
        array $data = [],
        ScopeConfigInterface $scopeConfig = null
    ) {
        parent::__construct($data);
        $this->urlFactory = $urlFactory;
        $this->storeManager = $storeManager;
        $this->filter = $filter;
        $this->sidResolver = $sidResolver;
        $this->urlFinder = $urlFinder;
        $this->scopeConfig = $scopeConfig ?:
            \Magento\Framework\App\ObjectManager::getInstance()->get(ScopeConfigInterface::class);
    }

    /**
     * Retrieve URL in current store
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param array $params the URL route params
     * @return string
     */
    public function getUrlInStore(\Magento\Catalog\Model\Product $product, $params = [])
    {
        $params['_scope_to_url'] = true;
        return $this->getUrl($product, $params);
    }

    /**
     * Retrieve Product URL
     *
     * @param  \Magento\Catalog\Model\Product $product
     * @param  bool $useSid forced SID mode
     * @return string
     */
    public function getProductUrl($product, $useSid = null)
    {
        $params = [];
        if (!$useSid) {
            $params['_nosid'] = true;
        }
        return $this->getUrl($product, $params);
    }

    /**
     * Format Key for URL
     *
     * @param string $str
     * @return string
     */
    public function formatUrlKey($str)
    {
        return $this->filter->translitUrl($str);
    }

    /**
     * Retrieve Product URL using UrlDataObject
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param array $params
     * @return string
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function getUrl(\Magento\Catalog\Model\Product $product, $params = [])
    {
        $routePath = '';
        $routeParams = $params;

        $storeId = $product->getStoreId();

        $categoryId = null;

        if (!isset($params['_ignore_category']) && $product->getCategoryId() && !$product->getDoNotUseCategoryId()) {
            $categoryId = $product->getCategoryId();
        }

        if ($product->hasUrlDataObject()) {
            $requestPath = $product->getUrlDataObject()->getUrlRewrite();
            $routeParams['_scope'] = $product->getUrlDataObject()->getStoreId();
        } else {
            $requestPath = $product->getRequestPath();
            if (empty($requestPath) && $requestPath !== false) {
                $filterData = [
                    UrlRewrite::ENTITY_ID => $product->getId(),
                    UrlRewrite::ENTITY_TYPE => \Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator::ENTITY_TYPE,
                    UrlRewrite::STORE_ID => $storeId,
                    UrlRewrite::REDIRECT_TYPE => 0
                ];
                $useCategories = $this->scopeConfig->getValue(
                    \Magento\Catalog\Helper\Product::XML_PATH_PRODUCT_URL_USE_CATEGORY,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );

                $filterData[UrlRewrite::METADATA]['category_id']
                    = $categoryId && $useCategories ? $categoryId : '';

                $rewrite = $this->urlFinder->findOneByData($filterData);

                if ($rewrite) {
                    $requestPath = $rewrite->getRequestPath();
                    $product->setRequestPath($requestPath);
                } else {
                    $product->setRequestPath(false);
                }
            }
        }

        if (isset($routeParams['_scope'])) {
            $storeId = $this->storeManager->getStore($routeParams['_scope'])->getId();
        }

        if ($storeId != $this->storeManager->getStore()->getId()) {
            $routeParams['_scope_to_url'] = true;
        }

        if (!empty($requestPath)) {
            $routeParams['_direct'] = $requestPath;
        } else {
            $routePath = 'catalog/product/view';
            $routeParams['id'] = $product->getId();
            $routeParams['s'] = $product->getUrlKey();
            if ($categoryId) {
                $routeParams['category'] = $categoryId;
            }
        }

        // reset cached URL instance GET query params
        if (!isset($routeParams['_query'])) {
            $routeParams['_query'] = [];
        }

        $url = $this->urlFactory->create()->setScope($storeId);
        return $url->getUrl($routePath, $routeParams);
    }
}
