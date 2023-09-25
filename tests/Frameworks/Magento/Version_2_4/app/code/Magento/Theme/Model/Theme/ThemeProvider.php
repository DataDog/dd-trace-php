<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Model\Theme;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Design\Theme\ListInterface;
use Magento\Framework\App\DeploymentConfig;

/**
 * Provide data for theme grid and for theme edit page
 */
class ThemeProvider implements \Magento\Framework\View\Design\Theme\ThemeProviderInterface
{
    /**
     * @var \Magento\Theme\Model\ResourceModel\Theme\CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var \Magento\Theme\Model\ThemeFactory
     */
    protected $themeFactory;

    /**
     * @var \Magento\Framework\App\CacheInterface
     */
    protected $cache;

    /**
     * @var \Magento\Framework\View\Design\ThemeInterface[]
     */
    private $themes;

    /**
     * @var ListInterface
     */
    private $themeList;

    /**
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @var Json
     */
    private $serializer;

    /**
     * ThemeProvider constructor.
     *
     * @param \Magento\Theme\Model\ResourceModel\Theme\CollectionFactory $collectionFactory
     * @param \Magento\Theme\Model\ThemeFactory $themeFactory
     * @param \Magento\Framework\App\CacheInterface $cache
     * @param Json $serializer
     */
    public function __construct(
        \Magento\Theme\Model\ResourceModel\Theme\CollectionFactory $collectionFactory,
        \Magento\Theme\Model\ThemeFactory $themeFactory,
        \Magento\Framework\App\CacheInterface $cache,
        Json $serializer = null
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->themeFactory = $themeFactory;
        $this->cache = $cache;
        $this->serializer = $serializer ?: ObjectManager::getInstance()->get(Json::class);
    }

    /**
     * @inheritdoc
     */
    public function getThemeByFullPath($fullPath)
    {
        if (isset($this->themes[$fullPath])) {
            return $this->themes[$fullPath];
        }

        if (! $this->getDeploymentConfig()->isDbAvailable()) {
            return $this->getThemeList()->getThemeByFullPath($fullPath);
        }

        $theme = $this->loadThemeFromCache('theme' . $fullPath);
        if ($theme) {
            $this->themes[$fullPath] = $theme;
            return $theme;
        }
        $themeCollection = $this->collectionFactory->create();
        $theme = $themeCollection->getThemeByFullPath($fullPath);
        if ($theme->getId()) {
            $this->saveThemeToCache($theme, 'theme' . $fullPath);
            $this->saveThemeToCache($theme, 'theme-by-id-' . $theme->getId());
        }
        $this->themes[$fullPath] = $theme;

        return $theme;
    }

    /**
     * @inheritdoc
     */
    public function getThemeCustomizations(
        $area = \Magento\Framework\App\Area::AREA_FRONTEND,
        $type = \Magento\Framework\View\Design\ThemeInterface::TYPE_VIRTUAL
    ) {
        /** @var $themeCollection \Magento\Theme\Model\ResourceModel\Theme\Collection */
        $themeCollection = $this->collectionFactory->create();
        $themeCollection->addAreaFilter($area)->addTypeFilter($type);
        return $themeCollection;
    }

    /**
     * @inheritdoc
     */
    public function getThemeById($themeId)
    {
        if (isset($this->themes[$themeId])) {
            return $this->themes[$themeId];
        }
        $theme = $this->loadThemeFromCache('theme-by-id-' . $themeId);
        if ($theme) {
            $this->themes[$themeId] = $theme;
            return $theme;
        }
        $theme = $this->themeFactory->create();
        $theme->load($themeId);
        if ($theme->getId()) {
            // We only cache by ID, as virtual themes may share the same path
            $this->saveThemeToCache($theme, 'theme-by-id-' . $themeId);
            $this->themes[$themeId] = $theme;
        }
        return $theme;
    }

    /**
     * Load Theme model from cache
     *
     * @param string $cacheId
     * @return \Magento\Theme\Model\Theme|null
     */
    private function loadThemeFromCache($cacheId)
    {
        $themeData = $this->cache->load($cacheId);
        if ($themeData) {
            $themeData = $this->serializer->unserialize($themeData);
            $theme = $this->themeFactory->create()->populateFromArray($themeData);
            return $theme;
        }

        return null;
    }

    /**
     * Save Theme model to the cache
     *
     * @param \Magento\Theme\Model\Theme $theme
     * @param string $cacheId
     * @return void
     */
    private function saveThemeToCache(\Magento\Theme\Model\Theme $theme, $cacheId)
    {
        $themeData = $this->serializer->serialize($theme->toArray());
        $this->cache->save($themeData, $cacheId);
    }

    /**
     * Get theme list
     *
     * @deprecated 100.1.3
     * @return ListInterface
     */
    private function getThemeList()
    {
        if ($this->themeList === null) {
            $this->themeList = ObjectManager::getInstance()->get(ListInterface::class);
        }
        return $this->themeList;
    }

    /**
     * Get deployment config
     *
     * @deprecated 100.1.3
     * @return DeploymentConfig
     */
    private function getDeploymentConfig()
    {
        if ($this->deploymentConfig === null) {
            $this->deploymentConfig = ObjectManager::getInstance()->get(DeploymentConfig::class);
        }
        return $this->deploymentConfig;
    }
}
