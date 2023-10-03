<?php

namespace Drupal\Composer\Generator\Builder;

use Drupal\Composer\Composer;

/**
 * Builder to produce metapackage for drupal/core-recommended.
 */
class DrupalCoreRecommendedBuilder extends DrupalPackageBuilder {

  /**
   * {@inheritdoc}
   */
  public function getPath() {
    return 'CoreRecommended';
  }

  /**
   * {@inheritdoc}
   */
  public function getPackage() {

    $composer = $this->initialPackageMetadata();

    // Pull up the composer lock data.
    $composerLockData = $this->drupalCoreInfo->composerLock();
    if (!isset($composerLockData['packages'])) {
      return $composer;
    }

    // Make a list of packages we do not want to put in the 'require' section.
    $remove_list = [
      'drupal/core',
      'wikimedia/composer-merge-plugin',
      'composer/installers',
      // This package contains no code other than interfaces, so allow sites
      // to use any compatible version without needing to switch off of
      // drupal/core-recommended.
      'psr/http-message',
      // Guzzle Promises is a dependency of some other libraries, so be less
      // restrictive here and trust Guzzle to maintain compatibility.
      'guzzlehttp/promises',
    ];

    // Copy the 'packages' section from the Composer lock into our 'require'
    // section. There is also a 'packages-dev' section, but we do not need
    // to pin 'require-dev' versions, as 'require-dev' dependencies are never
    // included from subprojects. Use 'drupal/core-dev' to get Drupal's
    // dev dependencies.
    foreach ($composerLockData['packages'] as $package) {
      // If there is no 'source' record, then this is a path repository
      // or something else that we do not want to include.
      if (isset($package['source']) && !in_array($package['name'], $remove_list)) {
        $composer['require'][$package['name']] = '~' . $package['version'];
      }
    }
    return $composer;
  }

  /**
   * Returns the initial package metadata that describes the metapackage.
   *
   * @return array
   */
  protected function initialPackageMetadata() {
    return [
      "name" => "drupal/core-recommended",
      "type" => "metapackage",
      "description" => "Core and its dependencies with known-compatible minor versions. Require this project INSTEAD OF drupal/core.",
      "license" => "GPL-2.0-or-later",
      "conflict" => [
        "webflo/drupal-core-strict" => "*",
      ],
      "require" => [
        "drupal/core" => Composer::drupalVersionBranch(),
      ],
    ];
  }

}
