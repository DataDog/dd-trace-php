<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Setup\Controller;

use Magento\Setup\Model\PackagesAuth;
use Magento\Setup\Model\PackagesData;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\View\Model\ViewModel;
use Magento\Setup\Model\Grid;

/**
 * Controller for extension grid tasks
 */
class ExtensionGrid extends AbstractActionController
{
    /**
     * @var PackagesData
     */
    private $packagesData;

    /**
     * @var PackagesAuth
     */
    private $packagesAuth;

    /**
     * @var Grid\Extension
     */
    private $gridExtension;

    /**
     * @param PackagesData $packagesData
     * @param PackagesAuth $packagesAuth
     * @param Grid\Extension $gridExtension
     */
    public function __construct(
        PackagesData $packagesData,
        PackagesAuth $packagesAuth,
        Grid\Extension $gridExtension
    ) {
        $this->packagesData = $packagesData;
        $this->packagesAuth = $packagesAuth;
        $this->gridExtension = $gridExtension;
    }

    /**
     * Index page action
     *
     * @return \Zend\View\Model\ViewModel
     */
    public function indexAction()
    {
        $view = new ViewModel();
        $view->setTerminal(true);
        return $view;
    }

    /**
     * Get extensions info action
     *
     * @return JsonModel
     * @throws \RuntimeException
     */
    public function extensionsAction()
    {
        $error = '';
        $lastSyncData = [];
        $authDetails = $this->packagesAuth->getAuthJsonData();
        $extensions = [];
        if ($authDetails) {
            try {
                $lastSyncData = $this->packagesData->syncPackagesData();
                $extensions = $this->gridExtension->getList();
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        return new JsonModel(
            [
                'success' => true,
                'extensions' => $extensions,
                'total' => count($extensions),
                'lastSyncData' => $lastSyncData,
                'error' => $error
            ]
        );
    }

    /**
     * Sync action
     *
     * @return JsonModel
     */
    public function syncAction()
    {
        $error = '';
        $lastSyncData = [];
        try {
            $authDataJson = $this->packagesAuth->getAuthJsonData();
            $this->packagesAuth->checkCredentials($authDataJson['username'], $authDataJson['password']);
            $lastSyncData = $this->packagesData->syncPackagesData();
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        return new JsonModel(
            [
                'success' => true,
                'lastSyncData' => $lastSyncData,
                'error' => $error
            ]
        );
    }
}
