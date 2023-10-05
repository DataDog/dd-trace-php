<?php

namespace Drupal\Tests\user\Functional;

use Drupal\Core\Url;
use Drupal\dynamic_page_cache\EventSubscriber\DynamicPageCacheSubscriber;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests user blocks.
 *
 * @group user
 */
class UserBlocksTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['block', 'views'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'classy';

  /**
   * A user with the 'administer blocks' permission.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  protected function setUp() {
    parent::setUp();

    $this->adminUser = $this->drupalCreateUser(['administer blocks']);
    $this->drupalLogin($this->adminUser);
    $this->drupalPlaceBlock('user_login_block');
    $this->drupalLogout($this->adminUser);
  }

  /**
   * Tests that user login block is hidden from user/login.
   */
  public function testUserLoginBlockVisibility() {
    // Array keyed list where key being the URL address and value being expected
    // visibility as boolean type.
    $paths = [
      'node' => TRUE,
      'user/login' => FALSE,
      'user/register' => TRUE,
      'user/password' => TRUE,
    ];
    foreach ($paths as $path => $expected_visibility) {
      $this->drupalGet($path);
      $elements = $this->xpath('//div[contains(@class,"block-user-login-block") and @role="form"]');
      if ($expected_visibility) {
        $this->assertTrue(!empty($elements), 'User login block in path "' . $path . '" should be visible');
      }
      else {
        $this->assertTrue(empty($elements), 'User login block in path "' . $path . '" should not be visible');
      }
    }
  }

  /**
   * Test the user login block.
   */
  public function testUserLoginBlock() {
    // Create a user with some permission that anonymous users lack.
    $user = $this->drupalCreateUser(['administer permissions']);

    // Log in using the block.
    $edit = [];
    $edit['name'] = $user->getAccountName();
    $edit['pass'] = $user->passRaw;
    $this->drupalPostForm('admin/people/permissions', $edit, t('Log in'));
    $this->assertNoText(t('User login'), 'Logged in.');

    // Check that we are still on the same page.
    $this->assertUrl(Url::fromRoute('user.admin_permissions', [], ['absolute' => TRUE])->toString(), [], 'Still on the same page after login for access denied page');

    // Now, log out and repeat with a non-403 page.
    $this->drupalLogout();
    $this->drupalGet('filter/tips');
    $this->assertEqual('MISS', $this->drupalGetHeader(DynamicPageCacheSubscriber::HEADER));
    $this->drupalPostForm(NULL, $edit, t('Log in'));
    $this->assertNoText(t('User login'), 'Logged in.');
    // Verify that we are still on the same page after login for allowed page.
    $this->assertPattern('!<title.*?Compose tips.*?</title>!');

    // Log out again and repeat with a non-403 page including query arguments.
    $this->drupalLogout();
    $this->drupalGet('filter/tips', ['query' => ['foo' => 'bar']]);
    $this->assertEqual('HIT', $this->drupalGetHeader(DynamicPageCacheSubscriber::HEADER));
    $this->drupalPostForm(NULL, $edit, t('Log in'));
    $this->assertNoText(t('User login'), 'Logged in.');
    // Verify that we are still on the same page after login for allowed page.
    $this->assertPattern('!<title.*?Compose tips.*?</title>!');
    $this->assertStringContainsString('/filter/tips?foo=bar', $this->getUrl(), 'Correct query arguments are displayed after login');

    // Repeat with different query arguments.
    $this->drupalLogout();
    $this->drupalGet('filter/tips', ['query' => ['foo' => 'baz']]);
    $this->assertEqual('HIT', $this->drupalGetHeader(DynamicPageCacheSubscriber::HEADER));
    $this->drupalPostForm(NULL, $edit, t('Log in'));
    $this->assertNoText(t('User login'), 'Logged in.');
    // Verify that we are still on the same page after login for allowed page.
    $this->assertPattern('!<title.*?Compose tips.*?</title>!');
    $this->assertStringContainsString('/filter/tips?foo=baz', $this->getUrl(), 'Correct query arguments are displayed after login');

    // Check that the user login block is not vulnerable to information
    // disclosure to third party sites.
    $this->drupalLogout();
    $this->drupalPostForm('http://example.com/', $edit, t('Log in'), ['external' => FALSE]);
    // Check that we remain on the site after login.
    $this->assertUrl($user->toUrl('canonical', ['absolute' => TRUE])->toString(), [], 'Redirected to user profile page after login from the frontpage');

    // Verify that form validation errors are displayed immediately for forms
    // in blocks and not on subsequent page requests.
    $this->drupalLogout();
    $edit = [];
    $edit['name'] = 'foo';
    $edit['pass'] = 'invalid password';
    $this->drupalPostForm('filter/tips', $edit, t('Log in'));
    $this->assertText(t('Unrecognized username or password. Forgot your password?'));
    $this->drupalGet('filter/tips');
    $this->assertNoText(t('Unrecognized username or password. Forgot your password?'));
  }

}
