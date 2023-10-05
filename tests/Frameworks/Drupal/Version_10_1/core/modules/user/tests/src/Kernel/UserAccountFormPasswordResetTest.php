<?php

namespace Drupal\Tests\user\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Verifies that the password reset behaves as expected with form elements.
 *
 * @group user
 */
class UserAccountFormPasswordResetTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['system', 'user'];

  /**
   * User object.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Install default configuration; required for AccountFormController.
    $this->installConfig(['user']);
    $this->installSchema('system', ['sequences']);
    $this->installEntitySchema('user');

    // Create an user to login.
    $this->user = User::create(['name' => 'test']);
    $this->user->save();

    // Set current user.
    $this->container->set('current_user', $this->user);
    // Install the router table and then rebuild.
    \Drupal::service('router.builder')->rebuild();
  }

  /**
   * Tests the reset token used only from query string.
   */
  public function testPasswordResetToken() {
    /** @var \Symfony\Component\HttpFoundation\Request $request */
    $request = $this->container->get('request_stack')->getCurrentRequest();

    // @todo: Replace with $request->getSession() as soon as the session is
    // present in KernelTestBase.
    // see: https://www.drupal.org/node/2484991
    $session = new Session();
    $request->setSession($session);

    $token = 'VALID_TOKEN';
    $session->set('pass_reset_1', $token);

    // Set token in query string.
    $request->query->set('pass-reset-token', $token);
    $form = $this->buildAccountForm('default');
    // User shouldn't see current password field.
    $this->assertFalse($form['account']['current_pass']['#access']);

    $request->query->set('pass-reset-token', NULL);
    $request->attributes->set('pass-reset-token', $token);
    $form = $this->buildAccountForm('default');
    $this->assertTrue($form['account']['current_pass']['#access']);
  }

  /**
   * Builds the user account form for a given operation.
   *
   * @param string $operation
   *   The entity operation; one of 'register' or 'default'.
   *
   * @return array
   *   The form array.
   */
  protected function buildAccountForm($operation) {
    // @see HtmlEntityFormController::getFormObject()
    $entity_type = 'user';
    if ($operation != 'register') {
      $entity = $this->user;
    }
    else {
      $entity = $this->container->get('entity_type.manager')
        ->getStorage($entity_type)
        ->create();
    }

    // @see EntityFormBuilder::getForm()
    return $this->container->get('entity.form_builder')->getForm($entity, $operation);
  }

}
