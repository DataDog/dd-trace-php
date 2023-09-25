<?php

namespace Drupal\FunctionalTests\Installer;

/**
 * Tests translation files for multiple languages get imported during install.
 *
 * @group Installer
 */
class InstallerTranslationExistingFileTest extends InstallerTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Overrides the language code in which to install Drupal.
   *
   * Choose one of the smaller languages on ftp.drupal.org. There is no way to
   * avoid using ftp.drupal.org since the code being tested runs extremely early
   * in the installer. However, even if the call to ftp.drupal.org fails then
   * this test will not fail as it will end up on the requirements page.
   *
   * @var string
   */
  protected $langcode = 'xx-lolspeak';

  /**
   * {@inheritdoc}
   */
  protected function setUpLanguage() {
    // Place custom local translations in the translations directory.
    mkdir(DRUPAL_ROOT . '/' . $this->siteDirectory . '/files/translations', 0777, TRUE);
    $po_contents = <<<ENDPO
msgid ""
msgstr ""
ENDPO;
    // Create a misnamed translation file that
    // \Drupal\Core\StringTranslation\Translator\FileTranslation::findTranslationFiles()
    // will not find.
    file_put_contents(DRUPAL_ROOT . '/' . $this->siteDirectory . '/files/translations/drupal-8.0.0-DEV.xx-lolspeak.po', $po_contents);
    parent::setUpLanguage();
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpProfile() {
    // Do nothing, because this test only tests the language installation
    // step's results.
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpSettings() {
    // Do nothing, because this test only tests the language installation
    // step's results.
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpRequirementsProblem() {
    // Do nothing, because this test only tests the language installation
    // step's results.
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpSite() {
    // Do nothing, because this test only tests the language installation
    // step's results.
  }

  /**
   * Ensures language selection has not failed.
   */
  public function testInstall() {
    // At this point we'll be on the profile selection or requirements screen.
    $this->assertSession()->statusCodeEquals(200);
  }

}
