<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Block\Widget;

use Magento\Framework\Cache\LockGuardedCacheLoader;

/**
 * Constructor modification point for Magento\Backend\Block\Widget.
 *
 * All context classes were introduced to allow for backwards compatible constructor modifications
 * of classes that were supposed to be extended by extension developers.
 *
 * Do not call methods of this class directly.
 *
 * As Magento moves from inheritance-based APIs all such classes will be deprecated together with
 * the classes they were introduced for.
 *
 * @api
 * @SuppressWarnings(PHPMD)
 * @since 100.0.2
 */
class Context extends \Magento\Backend\Block\Template\Context
{
    /**
     * @var \Magento\Backend\Block\Widget\Button\ButtonList
     */
    protected $buttonList;

    /**
     * @var \Magento\Backend\Block\Widget\Button\ToolbarInterface
     */
    protected $buttonToolbar;

    /**
     * @var \Magento\Framework\View\Page\Config
     */
    protected $pageConfig;

    /**
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Framework\View\LayoutInterface $layout
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \Magento\Framework\App\CacheInterface $cache
     * @param \Magento\Framework\View\DesignInterface $design
     * @param \Magento\Framework\Session\Generic $session
     * @param \Magento\Framework\Session\SidResolverInterface $sidResolver
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\View\Asset\Repository $assetRepo
     * @param \Magento\Framework\View\ConfigInterface $viewConfig
     * @param \Magento\Framework\App\Cache\StateInterface $cacheState
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\Escaper $escaper
     * @param \Magento\Framework\Filter\FilterManager $filterManager
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate
     * @param \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Framework\View\FileSystem $viewFileSystem
     * @param \Magento\Framework\View\TemplateEnginePool $enginePool
     * @param \Magento\Framework\App\State $appState
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\View\Page\Config $pageConfig
     * @param \Magento\Framework\View\Element\Template\File\Resolver $resolver
     * @param \Magento\Framework\View\Element\Template\File\Validator $validator
     * @param \Magento\Framework\AuthorizationInterface $authorization
     * @param \Magento\Backend\Model\Session $backendSession
     * @param \Magento\Framework\Math\Random $mathRandom
     * @param \Magento\Framework\Data\Form\FormKey $formKey
     * @param \Magento\Framework\Code\NameBuilder $nameBuilder
     * @param \Magento\Backend\Block\Widget\Button\ButtonList $buttonList
     * @param Button\ToolbarInterface $toolbar
     * @param LockGuardedCacheLoader|null $lockQuery
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\View\LayoutInterface $layout,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Framework\View\DesignInterface $design,
        \Magento\Framework\Session\Generic $session,
        \Magento\Framework\Session\SidResolverInterface $sidResolver,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Framework\View\ConfigInterface $viewConfig,
        \Magento\Framework\App\Cache\StateInterface $cacheState,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Escaper $escaper,
        \Magento\Framework\Filter\FilterManager $filterManager,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\View\FileSystem $viewFileSystem,
        \Magento\Framework\View\TemplateEnginePool $enginePool,
        \Magento\Framework\App\State $appState,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\View\Page\Config $pageConfig,
        \Magento\Framework\View\Element\Template\File\Resolver $resolver,
        \Magento\Framework\View\Element\Template\File\Validator $validator,
        \Magento\Framework\AuthorizationInterface $authorization,
        \Magento\Backend\Model\Session $backendSession,
        \Magento\Framework\Math\Random $mathRandom,
        \Magento\Framework\Data\Form\FormKey $formKey,
        \Magento\Framework\Code\NameBuilder $nameBuilder,
        Button\ButtonList $buttonList,
        Button\ToolbarInterface $toolbar,
        LockGuardedCacheLoader $lockQuery = null
    ) {
        parent::__construct(
            $request,
            $layout,
            $eventManager,
            $urlBuilder,
            $cache,
            $design,
            $session,
            $sidResolver,
            $scopeConfig,
            $assetRepo,
            $viewConfig,
            $cacheState,
            $logger,
            $escaper,
            $filterManager,
            $localeDate,
            $inlineTranslation,
            $filesystem,
            $viewFileSystem,
            $enginePool,
            $appState,
            $storeManager,
            $pageConfig,
            $resolver,
            $validator,
            $authorization,
            $backendSession,
            $mathRandom,
            $formKey,
            $nameBuilder,
            $lockQuery
        );

        $this->buttonList = $buttonList;
        $this->buttonToolbar = $toolbar;
    }

    /**
     * Get button list
     *
     * @return \Magento\Backend\Block\Widget\Button\ButtonList
     */
    public function getButtonList()
    {
        return $this->buttonList;
    }

    /**
     * Get button toolbar
     *
     * @return \Magento\Backend\Block\Widget\Button\ToolbarInterface
     */
    public function getButtonToolbar()
    {
        return $this->buttonToolbar;
    }
}
