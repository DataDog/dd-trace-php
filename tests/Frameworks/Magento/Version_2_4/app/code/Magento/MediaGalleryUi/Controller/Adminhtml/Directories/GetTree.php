<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\MediaGalleryUi\Controller\Adminhtml\Directories;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\MediaGalleryUi\Model\Directories\GetDirectoryTree;
use Psr\Log\LoggerInterface;

/**
 * Returns all available directories
 */
class GetTree extends Action implements HttpGetActionInterface
{
    private const HTTP_OK = 200;
    private const HTTP_INTERNAL_ERROR = 500;

    /**
     * @see _isAllowed()
     */
    public const ADMIN_RESOURCE = 'Magento_Cms::media_gallery';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var GetDirectoryTree
     */
    private $getDirectoryTree;

    /**
     * Constructor
     *
     * @param Action\Context $context
     * @param LoggerInterface $logger
     * @param GetDirectoryTree $getDirectoryTree
     */
    public function __construct(
        Action\Context $context,
        LoggerInterface $logger,
        GetDirectoryTree $getDirectoryTree
    ) {
        parent::__construct($context);
        $this->logger = $logger;
        $this->getDirectoryTree = $getDirectoryTree;
    }
    /**
     * @inheritdoc
     */
    public function execute()
    {
        try {
            $responseContent =
                $this->getDirectoryTree->execute()
            ;
            $responseCode = self::HTTP_OK;
        } catch (\Exception $exception) {
            $this->logger->critical($exception);
            $responseCode = self::HTTP_INTERNAL_ERROR;
            $responseContent = [
                'success' => false,
                'message' => __('Retrieving directories list failed.'),
            ];
        }

        /** @var Json $resultJson */
        $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $resultJson->setHttpResponseCode($responseCode);
        $resultJson->setData($responseContent);

        return $resultJson;
    }
}
