<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Model\CaseServices;

use Magento\Framework\ObjectManagerInterface;
use Magento\Signifyd\Model\MessageGenerators\GeneratorFactory;
use Magento\Signifyd\Model\Config;

/**
 * Creates instance of case updating service configured with specific message generator.
 * The message generator initialization depends on specified type (like, case creation, re-scoring, review and
 * guarantee completion).
 *
 * @deprecated 100.3.5 Starting from Magento 2.3.5 Signifyd core integration is deprecated in favor of
 * official Signifyd integration available on the marketplace
 */
class UpdatingServiceFactory
{
    /**
     * Type of testing Signifyd case
     * @var string
     */
    private static $caseTest = 'cases/test';

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var GeneratorFactory
     */
    private $generatorFactory;

    /**
     * @var Config
     */
    private $config;

    /**
     * UpdatingServiceFactory constructor.
     *
     * @param ObjectManagerInterface $objectManager
     * @param GeneratorFactory $generatorFactory
     * @param Config $config
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        GeneratorFactory $generatorFactory,
        Config $config
    ) {
        $this->objectManager = $objectManager;
        $this->generatorFactory = $generatorFactory;
        $this->config = $config;
    }

    /**
     * Creates instance of service updating case.
     * As param retrieves type of message generator.
     *
     * @param string $type
     * @return UpdatingServiceInterface
     * @throws \InvalidArgumentException
     */
    public function create($type)
    {
        if (!$this->config->isActive() || $type === self::$caseTest) {
            return $this->objectManager->create(StubUpdatingService::class);
        }

        $messageGenerator = $this->generatorFactory->create($type);
        $service = $this->objectManager->create(UpdatingService::class, [
            'messageGenerator' => $messageGenerator
        ]);

        return $service;
    }
}
