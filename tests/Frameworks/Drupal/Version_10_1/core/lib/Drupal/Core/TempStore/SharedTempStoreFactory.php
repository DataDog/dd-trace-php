<?php

namespace Drupal\Core\TempStore;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Creates a shared temporary storage for a collection.
 */
class SharedTempStoreFactory {

  /**
   * The storage factory creating the backend to store the data.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface
   */
  protected $storageFactory;

  /**
   * The lock object used for this data.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lockBackend;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The time to live for items in seconds.
   *
   * @var int
   */
  protected $expire;

  /**
   * Constructs a Drupal\Core\TempStore\SharedTempStoreFactory object.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $storage_factory
   *   The key/value store factory.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock_backend
   *   The lock object used for this data.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param int $expire
   *   The time to live for items, in seconds.
   */
  public function __construct(KeyValueExpirableFactoryInterface $storage_factory, LockBackendInterface $lock_backend, RequestStack $request_stack, AccountProxyInterface $current_user, $expire = 604800) {
    $this->storageFactory = $storage_factory;
    $this->lockBackend = $lock_backend;
    $this->requestStack = $request_stack;
    $this->currentUser = $current_user;
    $this->expire = $expire;
  }

  /**
   * Creates a SharedTempStore for the current user or anonymous session.
   *
   * @param string $collection
   *   The collection name to use for this key/value store. This is typically
   *   a shared namespace or module name, e.g. 'views', 'entity', etc.
   * @param mixed $owner
   *   (optional) The owner of this SharedTempStore. By default, the
   *   SharedTempStore is owned by the currently authenticated user, or by the
   *   active anonymous session if no user is logged in.
   *
   * @return \Drupal\Core\TempStore\SharedTempStore
   *   An instance of the key/value store.
   */
  public function get($collection, $owner = NULL) {
    // Use the currently authenticated user ID or the active user ID unless
    // the owner is overridden.
    if (!isset($owner)) {
      $owner = $this->currentUser->id();
      if ($this->currentUser->isAnonymous()) {
        $owner = Crypt::randomBytesBase64();
        if ($this->requestStack->getCurrentRequest()->hasSession()) {
          // Store a random identifier for anonymous users if the session is
          // available.
          $owner = $this->requestStack->getCurrentRequest()->getSession()->get('core.tempstore.shared.owner', $owner);
        }
      }
    }

    // Store the data for this collection in the database.
    $storage = $this->storageFactory->get("tempstore.shared.$collection");
    return new SharedTempStore($storage, $this->lockBackend, $owner, $this->requestStack, $this->currentUser, $this->expire);
  }

}
