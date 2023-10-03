<?php

namespace Drupal\Core\Asset;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;

/**
 * Dumps a CSS or JavaScript asset.
 */
class AssetDumper implements AssetDumperInterface {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * AssetDumper constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file handler.
   */
  public function __construct(FileSystemInterface $file_system) {
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   *
   * The file name for the CSS or JS cache file is generated from the hash of
   * the aggregated contents of the files in $data. This forces proxies and
   * browsers to download new CSS when the CSS changes.
   */
  public function dump($data, $file_extension) {
    // Prefix filename to prevent blocking by firewalls which reject files
    // starting with "ad*".
    $filename = $file_extension . '_' . Crypt::hashBase64($data) . '.' . $file_extension;
    // Create the css/ or js/ path within the files folder.
    $path = 'public://' . $file_extension;
    $uri = $path . '/' . $filename;
    // Create the CSS or JS file.
    $this->fileSystem->prepareDirectory($path, FileSystemInterface::CREATE_DIRECTORY);
    try {
      if (!file_exists($uri) && !$this->fileSystem->saveData($data, $uri, FileSystemInterface::EXISTS_REPLACE)) {
        return FALSE;
      }
    }
    catch (FileException $e) {
      return FALSE;
    }
    // If CSS/JS gzip compression is enabled and the zlib extension is available
    // then create a gzipped version of this file. This file is served
    // conditionally to browsers that accept gzip using .htaccess rules.
    // It's possible that the rewrite rules in .htaccess aren't working on this
    // server, but there's no harm (other than the time spent generating the
    // file) in generating the file anyway. Sites on servers where rewrite rules
    // aren't working can set css.gzip to FALSE in order to skip
    // generating a file that won't be used.
    if (extension_loaded('zlib') && \Drupal::config('system.performance')->get($file_extension . '.gzip')) {
      try {
        if (!file_exists($uri . '.gz') && !$this->fileSystem->saveData(gzencode($data, 9, FORCE_GZIP), $uri . '.gz', FileSystemInterface::EXISTS_REPLACE)) {
          return FALSE;
        }
      }
      catch (FileException $e) {
        return FALSE;
      }
    }
    return $uri;
  }

}
