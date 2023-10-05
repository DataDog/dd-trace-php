<?php

namespace Drupal\file\Upload;

/**
 * Provides an interface for uploaded files.
 */
interface UploadedFileInterface {

  /**
   * Returns the original file name.
   *
   * The file name is extracted from the request that uploaded the file and as
   * such should not be considered a safe value.
   *
   * @return string
   *   The original file name supplied by the client.
   */
  public function getClientOriginalName(): string;

  /**
   * Returns whether the file was uploaded successfully.
   *
   * @return bool
   *   TRUE if the file has been uploaded with HTTP and no error occurred.
   */
  public function isValid(): bool;

  /**
   * Returns an informative upload error message.
   *
   * @return string
   *   The error message regarding a failed upload.
   */
  public function getErrorMessage(): string;

  /**
   * Returns the upload error code.
   *
   * If the upload was successful, the constant UPLOAD_ERR_OK is returned.
   * Otherwise, one of the other UPLOAD_ERR_XXX constants is returned.
   *
   * @return int
   *   The upload error code.
   */
  public function getError(): int;

  /**
   * Gets file size.
   *
   * @return int
   *   The filesize in bytes.
   *
   * @see https://www.php.net/manual/en/splfileinfo.getsize.php
   */
  public function getSize(): int;

  /**
   * Gets the absolute path to the file.
   *
   * @return string|false
   *   The path to the file, or FALSE if the file does not exist.
   *
   * @see https://php.net/manual/en/splfileinfo.getrealpath.php
   */
  public function getRealPath();

  /**
   * Gets the path to the file.
   *
   * @return string
   *   The path to the file.
   *
   * @see https://php.net/manual/en/splfileinfo.getpathname.php
   */
  public function getPathname(): string;

  /**
   * Gets the filename.
   *
   * @return string
   *   The filename.
   *
   * @see https://php.net/manual/en/splfileinfo.getfilename.php
   */
  public function getFilename(): string;

}
