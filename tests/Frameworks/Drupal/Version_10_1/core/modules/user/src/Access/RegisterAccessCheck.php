<?php

namespace Drupal\user\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * Access check for user registration routes.
 */
class RegisterAccessCheck implements AccessInterface {

  /**
   * Checks access.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account) {
    $user_settings = \Drupal::config('user.settings');
    return AccessResult::allowedIf($account->isAnonymous() && $user_settings->get('register') != UserInterface::REGISTER_ADMINISTRATORS_ONLY)->addCacheableDependency($user_settings);
  }

}
