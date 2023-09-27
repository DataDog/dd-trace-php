<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Wishlist\Controller;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;

/**
 * WishlistProvider Controller
 *
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class WishlistProvider implements WishlistProviderInterface
{
    /**
     * @var \Magento\Wishlist\Model\Wishlist
     */
    protected $wishlist;

    /**
     * @var \Magento\Wishlist\Model\WishlistFactory
     */
    protected $wishlistFactory;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $customerSession;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @param \Magento\Wishlist\Model\WishlistFactory $wishlistFactory
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     * @param RequestInterface $request
     */
    public function __construct(
        \Magento\Wishlist\Model\WishlistFactory $wishlistFactory,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        RequestInterface $request
    ) {
        $this->request = $request;
        $this->wishlistFactory = $wishlistFactory;
        $this->customerSession = $customerSession;
        $this->messageManager = $messageManager;
    }

    /**
     * @inheritdoc
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function getWishlist($wishlistId = null)
    {
        if ($this->wishlist) {
            return $this->wishlist;
        }
        try {
            if (!$wishlistId) {
                $wishlistId = $this->request->getParam('wishlist_id');
            }
            $customerId = $this->customerSession->getCustomerId();
            $wishlist = $this->wishlistFactory->create();

            if (!$wishlistId && !$customerId) {
                return $wishlist;
            }

            if ($wishlistId) {
                $wishlist->load($wishlistId);
            } elseif ($customerId) {
                $wishlist->loadByCustomerId($customerId, true);
            }

            if (!$wishlist->getId() || $wishlist->getCustomerId() != $customerId) {
                throw new \Magento\Framework\Exception\NoSuchEntityException(
                    __('The requested Wish List doesn\'t exist.')
                );
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('We can\'t create the Wish List right now.'));
            return false;
        }
        $this->wishlist = $wishlist;
        return $wishlist;
    }
}
