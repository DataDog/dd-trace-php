<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Controller\Adminhtml\Cache;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\State;
use Magento\Framework\App\ObjectManager;

/**
 * Controller disables some types of cache
 */
class MassDisable extends \Magento\Backend\Controller\Adminhtml\Cache
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_Backend::toggling_cache_type';

    /**
     * @var State
     */
    private $state;

    /**
     * Mass action for cache disabling
     *
     * @return \Magento\Backend\Model\View\Result\Redirect
     */
    public function execute()
    {
        if ($this->getState()->getMode() === State::MODE_PRODUCTION) {
            $this->messageManager->addErrorMessage(__('You can\'t change status of cache type(s) in production mode'));
        } else {
            $this->disableCache();
        }

        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('adminhtml/*');
    }

    /**
     * Disable cache
     *
     * @return void
     */
    private function disableCache()
    {
        try {
            $types = $this->getRequest()->getParam('types');
            $updatedTypes = 0;
            if (!is_array($types)) {
                $types = [];
            }
            $this->_validateTypes($types);
            foreach ($types as $code) {
                $this->_cacheTypeList->cleanType($code);
                if ($this->_cacheState->isEnabled($code)) {
                    $this->_cacheState->setEnabled($code, false);
                    $updatedTypes++;
                }
            }
            if ($updatedTypes > 0) {
                $this->_cacheState->persist();
                $this->messageManager->addSuccessMessage(__("%1 cache type(s) disabled.", $updatedTypes));
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('An error occurred while disabling cache.'));
        }
    }

    /**
     * Get State Instance
     *
     * @return State
     * @deprecated 100.2.0
     */
    private function getState()
    {
        if ($this->state === null) {
            $this->state = ObjectManager::getInstance()->get(State::class);
        }

        return $this->state;
    }
}
