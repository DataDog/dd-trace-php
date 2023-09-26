<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\View\Asset;

use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Service model responsible for making a decision of whether to use the merged asset in place of original ones
 */
class MergeService
{
    /**
     * Object Manager
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * Config
     *
     * @var ConfigInterface
     */
    protected $config;

    /**
     * Filesystem
     *
     * @var \Magento\Framework\Filesystem
     */
    protected $filesystem;

    /**
     * State
     *
     * @var \Magento\Framework\App\State
     */
    protected $state;

    /**
     * List of supported types that can be processed by merge service
     *
     * @var array
     */
    private const SUPPORTED_MERGE_TYPE = [
        'css',
        'js',
        'eot',
        'svg',
        'ttf',
        'woff',
        'woff2',
    ];

    /**
     * Constructor
     *
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param ConfigInterface $config
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Framework\App\State $state
     */
    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        ConfigInterface $config,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\App\State $state
    ) {
        $this->objectManager = $objectManager;
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->state = $state;
    }

    /**
     * Return merged assets, if merging is enabled for a given content type
     *
     * @param MergeableInterface[] $assets
     * @param string $contentType
     * @return array|\Iterator
     * @throws \InvalidArgumentException
     */
    public function getMergedAssets(array $assets, $contentType)
    {
        $isCss = $contentType == 'css';
        $isJs = $contentType == 'js';
        if (!in_array($contentType, self::SUPPORTED_MERGE_TYPE, true)) {
            throw new \InvalidArgumentException("Merge for content type '{$contentType}' is not supported.");
        }

        $isCssMergeEnabled = $this->config->isMergeCssFiles();
        $isJsMergeEnabled = $this->config->isMergeJsFiles();
        if (($isCss && $isCssMergeEnabled) || ($isJs && $isJsMergeEnabled)) {
            $mergeStrategyClass = \Magento\Framework\View\Asset\MergeStrategy\FileExists::class;

            if ($this->state->getMode() === \Magento\Framework\App\State::MODE_DEVELOPER) {
                $mergeStrategyClass = \Magento\Framework\View\Asset\MergeStrategy\Checksum::class;
            }

            $mergeStrategy = $this->objectManager->get($mergeStrategyClass);

            $assets = $this->objectManager->create(
                \Magento\Framework\View\Asset\Merged::class,
                ['assets' => $assets, 'mergeStrategy' => $mergeStrategy]
            );
        }

        return $assets;
    }

    /**
     * Remove all merged js/css files
     *
     * @return void
     */
    public function cleanMergedJsCss()
    {
        $this->filesystem->getDirectoryWrite(DirectoryList::STATIC_VIEW)
            ->delete(Merged::getRelativeDir());
    }
}
