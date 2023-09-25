<?php

namespace Drupal\user\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Updates the current user's last access time.
 */
class UserRequestSubscriber implements EventSubscriberInterface {

  /**
   * The current account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new UserRequestSubscriber.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(AccountInterface $account, EntityTypeManagerInterface $entity_type_manager) {
    $this->account = $account;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Updates the current user's last access time.
   *
   * @param \Symfony\Component\HttpKernel\Event\TerminateEvent $event
   *   The event to process.
   */
  public function onKernelTerminate(TerminateEvent $event) {
    if ($this->account->isAuthenticated() && REQUEST_TIME - $this->account->getLastAccessedTime() > Settings::get('session_write_interval', 180)) {
      // Do that no more than once per 180 seconds.
      /** @var \Drupal\user\UserStorageInterface $storage */
      $storage = $this->entityTypeManager->getStorage('user');
      $storage->updateLastAccessTimestamp($this->account, REQUEST_TIME);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Should go before other subscribers start to write their caches. Notably
    // before \Drupal\Core\EventSubscriber\KernelDestructionSubscriber to
    // prevent instantiation of destructed services.
    $events[KernelEvents::TERMINATE][] = ['onKernelTerminate', 300];
    return $events;
  }

}
