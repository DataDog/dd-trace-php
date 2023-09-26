<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\File;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\TargetDirectory;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Validation\ValidationException;
use Psr\Log\LoggerInterface;

/**
 * File upload class
 *
 * ATTENTION! This class must be used like abstract class and must added
 * validation by protected file extension list to extended class
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @api
 * @since 100.0.2
 */
class Uploader
{
    /**
     * Uploaded file handle (copy of $_FILES[] element)
     *
     * @var array
     * @access protected
     */
    protected $_file;

    /**
     * Uploaded file mime type
     *
     * @var string
     * @access protected
     */
    protected $_fileMimeType;

    /**
     * Upload type. Used to right handle $_FILES array.
     * @var Uploader::SINGLE_STYLE|\Magento\Framework\File\Uploader::MULTIPLE_STYLE
     * @access protected
     */
    protected $_uploadType;

    /**
     * The name of uploaded file. By default it is original file name, but when
     * we will change file name, this variable will be changed too.
     *
     * @var string
     * @access protected
     */
    protected $_uploadedFileName;

    /**
     * The name of destination directory
     *
     * @var string
     * @access protected
     */
    protected $_uploadedFileDir;

    /**
     * If this variable is set to TRUE, our library will be able to automatically create
     * non-existent directories.
     *
     * @var bool
     * @access protected
     */
    protected $_allowCreateFolders = true;

    /**
     * If this variable is set to TRUE, uploaded file name will be changed if some file with the same
     * name already exists in the destination directory (if enabled).
     *
     * @var bool
     * @access protected
     */
    protected $_allowRenameFiles = false;

    /**
     * If this variable is set to TRUE, files dispersion will be supported.
     *
     * @var bool
     * @access protected
     */
    protected $_enableFilesDispersion = false;

    /**
     * This variable is used both with $_enableFilesDispersion == true
     * It helps to avoid problems after migrating from case-insensitive file system to case-insensitive
     * (e.g. NTFS->ext or ext->NTFS)
     *
     * @var bool
     * @access protected
     */
    protected $_caseInsensitiveFilenames = true;

    /**
     * @var string
     * @access protected
     */
    protected $_dispretionPath = null;

    /**
     * @var bool
     */
    protected $_fileExists = false;

    /**
     * @var null|string[]
     */
    protected $_allowedExtensions = null;

    /**
     * Validate callbacks storage
     *
     * @var array
     * @access protected
     */
    protected $_validateCallbacks = [];

    /**
     * @var \Magento\Framework\File\Mime
     */
    private $fileMime;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**#@+
     * File upload type (multiple or single)
     */
    public const SINGLE_STYLE = 0;

    public const MULTIPLE_STYLE = 1;

    /**#@-*/

    /**
     * Temp file name empty code
     */
    public const TMP_NAME_EMPTY = 666;

    /**
     * Maximum Image Width resolution in pixels. For image resizing on client side
     * @deprecated @see \Magento\Framework\Image\Adapter\UploadConfigInterface::getMaxWidth()
     */
    public const MAX_IMAGE_WIDTH = 1920;

    /**
     * Maximum Image Height resolution in pixels. For image resizing on client side
     * @deprecated @see \Magento\Framework\Image\Adapter\UploadConfigInterface::getMaxHeight()
     */
    public const MAX_IMAGE_HEIGHT = 1200;

    /**
     * Maximum file name length
     */
    private const MAX_FILE_NAME_LENGTH = 255;

    /**
     * Resulting of uploaded file
     *
     * @var array|bool      Array with file info keys: path, file. Result is
     *                      FALSE when file not uploaded
     */
    protected $_result;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var DriverPool|null
     */
    private $driverPool;

    /**
     * @var DriverInterface|null
     */
    private $fileDriver;

    /**
     * @var TargetDirectory
     */
    private $targetDirectory;

    /**
     * Init upload
     *
     * @param string|array $fileId
     * @param \Magento\Framework\File\Mime|null $fileMime
     * @param DirectoryList|null $directoryList
     * @param DriverPool|null $driverPool
     * @param TargetDirectory|null $targetDirectory
     * @param Filesystem|null $filesystem
     * @throws \DomainException
     */
    public function __construct(
        $fileId,
        Mime $fileMime = null,
        DirectoryList $directoryList = null,
        DriverPool $driverPool = null,
        TargetDirectory $targetDirectory = null,
        Filesystem $filesystem = null
    ) {
        $this->directoryList = $directoryList ?: ObjectManager::getInstance()->get(DirectoryList::class);
        $this->targetDirectory = $targetDirectory ?: ObjectManager::getInstance()->get(TargetDirectory::class);

        $this->filesystem = $filesystem ?: ObjectManager::getInstance()->get(FileSystem::class);
        $this->_setUploadFileId($fileId);
        if (!file_exists($this->_file['tmp_name'])) {
            $code = empty($this->_file['tmp_name']) ? self::TMP_NAME_EMPTY : 0;
            throw new \DomainException('The file was not uploaded.', $code);
        } else {
            $this->_fileExists = true;
        }
        $this->fileMime = $fileMime ?: ObjectManager::getInstance()->get(Mime::class);
        $this->driverPool = $driverPool ?: ObjectManager::getInstance()->get(DriverPool::class);
    }

    /**
     * After save logic
     *
     * @param  array $result
     * @return $this
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _afterSave($result)
    {
        return $this;
    }

    /**
     * Used to save uploaded file into destination folder with original or new file name (if specified).
     *
     * @param string $destinationFolder
     * @param string $newFileName
     * @return array
     * @throws \Exception
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function save($destinationFolder, $newFileName = null)
    {
        $this->_validateFile();
        $this->validateDestination($destinationFolder);

        $this->_result = false;
        $destinationFile = $destinationFolder;
        $fileName = $newFileName ?? $this->_file['name'];
        $fileName = static::getCorrectFileName($fileName);
        if ($this->_enableFilesDispersion) {
            $fileName = $this->correctFileNameCase($fileName);
            $this->setAllowCreateFolders(true);
            $this->_dispretionPath = static::getDispersionPath($fileName);
            $destinationFile .= $this->_dispretionPath;
            $this->createDestinationFolder($destinationFile);
        }

        if ($this->_allowRenameFiles) {
            $fileName = static::getNewFileName(
                static::_addDirSeparator($destinationFile) . $fileName
            );
        }

        $destinationFile = static::_addDirSeparator($destinationFile) . $fileName;

        try {
            $this->_result = $this->_moveFile($this->_file['tmp_name'], $destinationFile);
        } catch (\Exception $e) {
            // if the file exists and we had an exception continue anyway
            if (file_exists($destinationFile)) {
                $this->_result = true;
            } else {
                throw $e;
            }
        }

        if ($this->_result) {
            if ($this->_enableFilesDispersion) {
                $fileName = str_replace('\\', '/', self::_addDirSeparator($this->_dispretionPath)) . $fileName;
            }
            $this->_uploadedFileName = $fileName;
            $this->_uploadedFileDir = $destinationFolder;
            $this->_result = $this->_file;
            $this->_result['path'] = $destinationFolder;
            $this->_result['file'] = $fileName;

            $this->_afterSave($this->_result);
        }

        return $this->_result;
    }

    /**
     * Validates destination directory to be writable
     *
     * @param string $destinationFolder
     * @return void
     * @throws FileSystemException
     */
    private function validateDestination(string $destinationFolder): void
    {
        if (strlen($this->getFileDriver()->getRealPathSafety($destinationFolder)) > 4096) {
            throw new \InvalidArgumentException(
                'Destination folder path is too long; must be 255 characters or less'
            );
        }
        if ($this->_allowCreateFolders) {
            $this->createDestinationFolder($destinationFolder);
        } elseif (!$this->getTargetDirectory()
            ->getDirectoryWrite(DirectoryList::ROOT)
            ->isWritable($destinationFolder)
        ) {
            throw new FileSystemException(__('Destination folder is not writable or does not exists.'));
        }
    }

    /**
     * Set access permissions to file.
     *
     * @param string $file
     * @return void
     *
     * @deprecated 100.0.8
     */
    protected function chmod($file)
    {
        chmod($file, 0777);
    }

    /**
     * Move files from TMP folder into destination folder
     *
     * @param string $tmpPath
     * @param string $destPath
     * @return bool
     */
    protected function _moveFile($tmpPath, $destPath)
    {
        $rootCode = DirectoryList::PUB;

        try {
            $path = $this->getDirectoryList()->getPath($rootCode) ?: '';
            $destPath = $destPath ?: '';
            if (strpos($destPath, $path) !== 0) {
                $rootCode = DirectoryList::ROOT;
            }

            $destPath = str_replace($path, '', $destPath);
            $directory = $this->getTargetDirectory()->getDirectoryWrite($rootCode);

            return $this->getFileDriver()->rename(
                $tmpPath,
                $directory->getAbsolutePath($destPath),
                $directory->getDriver()
            );
        } catch (FileSystemException $exception) {
            $this->getLogger()->critical($exception->getMessage());
            return false;
        }
    }

    /**
     * Get logger instance.
     *
     * @deprecated
     * @return LoggerInterface
     */
    private function getLogger(): LoggerInterface
    {
        if (!$this->logger) {
            $this->logger = ObjectManager::getInstance()->get(LoggerInterface::class);
        }
        return $this->logger;
    }

    /**
     * Retrieves target directory.
     *
     * @return TargetDirectory
     */
    private function getTargetDirectory(): TargetDirectory
    {
        if (!isset($this->targetDirectory)) {
            $this->targetDirectory = ObjectManager::getInstance()->get(TargetDirectory::class);
        }

        return $this->targetDirectory;
    }

    /**
     * Retrieves directory list.
     *
     * @return DirectoryList
     */
    private function getDirectoryList(): DirectoryList
    {
        if (!isset($this->directoryList)) {
            $this->directoryList = ObjectManager::getInstance()->get(DirectoryList::class);
        }

        return $this->directoryList;
    }

    /**
     * Validate file before save
     *
     * @return void
     * @throws ValidationException
     */
    protected function _validateFile()
    {
        if ($this->_fileExists === false) {
            return;
        }

        //is file extension allowed
        if (!$this->checkAllowedExtension($this->getFileExtension())) {
            throw new ValidationException(__('Disallowed file type.'));
        }
        //run validate callbacks
        foreach ($this->_validateCallbacks as $params) {
            if (is_object($params['object'])
                && method_exists($params['object'], $params['method'])
                && is_callable([$params['object'], $params['method']])
            ) {
                $params['object']->{$params['method']}($this->_file['tmp_name']);
            }
        }
    }

    /**
     * Returns extension of the uploaded file
     *
     * @return string
     */
    public function getFileExtension()
    {
        return $this->_fileExists ? pathinfo($this->_file['name'], PATHINFO_EXTENSION) : '';
    }

    /**
     * Add validation callback model for us in self::_validateFile()
     *
     * @param string $callbackName
     * @param object $callbackObject
     * @param string $callbackMethod    Method name of $callbackObject. It must
     *                                  have interface (string $tmpFilePath)
     * @return \Magento\Framework\File\Uploader
     */
    public function addValidateCallback($callbackName, $callbackObject, $callbackMethod)
    {
        $this->_validateCallbacks[$callbackName] = ['object' => $callbackObject, 'method' => $callbackMethod];
        return $this;
    }

    /**
     * Delete validation callback model for us in self::_validateFile()
     *
     * @param string $callbackName
     * @access public
     * @return \Magento\Framework\File\Uploader
     */
    public function removeValidateCallback($callbackName)
    {
        if (isset($this->_validateCallbacks[$callbackName])) {
            unset($this->_validateCallbacks[$callbackName]);
        }
        return $this;
    }

    /**
     * Correct filename with special chars and spaces; also trim excessively long filenames
     *
     * @param string $fileName
     * @return string
     * @throws \InvalidArgumentException
     */
    public static function getCorrectFileName($fileName)
    {
        $fileName = $fileName !== null ? ltrim($fileName, '.') : '';
        $fileName = preg_replace('/[^a-z0-9_\\-\\.]+/i', '_', $fileName);
        $fileInfo = pathinfo($fileName);
        $fileInfo['extension'] = $fileInfo['extension'] ?? '';

        if (strlen($fileInfo['basename'] ?? '') > self::MAX_FILE_NAME_LENGTH) {
            throw new \LengthException(
                __('Filename is too long; must be %1 characters or less', self::MAX_FILE_NAME_LENGTH)
            );
        }

        if (preg_match('/^_+$/', $fileInfo['filename'] ?? '')) {
            $fileName = 'file.' . $fileInfo['extension'];
        }

        return $fileName;
    }

    /**
     * Convert filename to lowercase in case of case-insensitive file names
     *
     * @param string $fileName
     * @return string
     */
    public function correctFileNameCase($fileName)
    {
        if ($this->_caseInsensitiveFilenames) {
            return strtolower($fileName);
        }
        return $fileName;
    }

    /**
     * Add directory separator
     *
     * @param string $dir
     * @return string
     */
    protected static function _addDirSeparator($dir)
    {
        if (!$dir || substr($dir, -1) != '/') {
            $dir .= '/';
        }
        return $dir;
    }

    /**
     * Used to check if uploaded file mime type is valid or not
     *
     * @param string[] $validTypes
     * @access public
     * @return bool
     */
    public function checkMimeType($validTypes = [])
    {
        if (count($validTypes) > 0) {
            if (!in_array($this->_getMimeType(), $validTypes)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns a name of uploaded file
     *
     * @access public
     * @return string
     */
    public function getUploadedFileName()
    {
        return $this->_uploadedFileName;
    }

    /**
     * Used to set {@link _allowCreateFolders} value
     *
     * @param bool $flag
     * @access public
     * @return $this
     */
    public function setAllowCreateFolders($flag)
    {
        $this->_allowCreateFolders = $flag;
        return $this;
    }

    /**
     * Used to set {@link _allowRenameFiles} value
     *
     * @param bool $flag
     * @access public
     * @return $this
     */
    public function setAllowRenameFiles($flag)
    {
        $this->_allowRenameFiles = $flag;
        return $this;
    }

    /**
     * Used to set {@link _enableFilesDispersion} value
     *
     * @param bool $flag
     * @access public
     * @return $this
     */
    public function setFilesDispersion($flag)
    {
        $this->_enableFilesDispersion = $flag;
        return $this;
    }

    /**
     * File names Case-sensitivity setter
     *
     * @param bool $flag
     * @return $this
     */
    public function setFilenamesCaseSensitivity($flag)
    {
        $this->_caseInsensitiveFilenames = $flag;
        return $this;
    }

    /**
     * Set allowed extensions
     *
     * @param string[] $extensions
     * @return $this
     */
    public function setAllowedExtensions($extensions = [])
    {
        foreach ((array)$extensions as $extension) {
            $this->_allowedExtensions[] = $extension !== null ? strtolower($extension) : '';
        }
        return $this;
    }

    /**
     * Check if specified extension is allowed
     *
     * @param string $extension
     * @return boolean
     */
    public function checkAllowedExtension($extension)
    {
        //File extensions should only be allowed to contain alphanumeric characters
        if ($extension && preg_match('/[^a-z0-9]/i', $extension)) {
            return false;
        }

        if (!is_array($this->_allowedExtensions) || empty($this->_allowedExtensions)) {
            return true;
        }

        return $extension && in_array(strtolower($extension), $this->_allowedExtensions);
    }

    /**
     * Return file mime type
     *
     * @return string
     */
    private function _getMimeType()
    {
        return $this->fileMime->getMimeType($this->_file['tmp_name']);
    }

    /**
     * Set upload field id
     *
     * @param string|array $fileId
     * @return void
     * @throws \DomainException
     * @throws \InvalidArgumentException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function _setUploadFileId($fileId)
    {
        if (is_array($fileId)) {
            $this->validateFileId($fileId);
            $this->_uploadType = self::MULTIPLE_STYLE;
            $this->_file = $fileId;
        } else {
            if (empty($_FILES)) {
                throw new \DomainException('$_FILES array is empty');
            }

            $fileId = $fileId !== null ? $fileId : '';
            preg_match("/^(.*?)\[(.*?)\]$/", $fileId, $file);

            if (is_array($file) && count($file) > 0 && !empty($file[0]) && !empty($file[1])) {
                array_shift($file);
                $this->_uploadType = self::MULTIPLE_STYLE;

                $fileAttributes = $_FILES[$file[0]];
                $tmpVar = [];

                foreach ($fileAttributes as $attributeName => $attributeValue) {
                    $tmpVar[$attributeName] = $attributeValue[$file[1]];
                }

                $fileAttributes = $tmpVar;
                $this->_file = $fileAttributes;
            } elseif (!empty($fileId) && isset($_FILES[$fileId])) {
                $this->_uploadType = self::SINGLE_STYLE;
                $this->_file = $_FILES[$fileId];
            } elseif ($fileId == '') {
                throw new \InvalidArgumentException(
                    'Invalid parameter given. A valid $_FILES[] identifier is expected.'
                );
            }
        }
    }

    /**
     * Validates explicitly given uploaded file data.
     *
     * @param array $fileId
     * @return void
     * @throws \InvalidArgumentException
     * @throws FileSystemException
     */
    private function validateFileId(array $fileId): void
    {
        $isValid = false;
        if (isset($fileId['tmp_name'])) {
            $tmpName = trim($fileId['tmp_name']);

            if (preg_match('/\.\.(\\\|\/)/', $tmpName) !== 1) {
                $allowedFolders = [
                    sys_get_temp_dir(),
                    $this->directoryList->getPath(DirectoryList::SYS_TMP),
                    $this->directoryList->getPath(DirectoryList::MEDIA),
                    $this->directoryList->getPath(DirectoryList::VAR_DIR),
                    $this->directoryList->getPath(DirectoryList::TMP),
                    $this->directoryList->getPath(DirectoryList::UPLOAD),
                ];

                $disallowedFolders = [
                    $this->directoryList->getPath(DirectoryList::LOG),
                ];

                foreach ($allowedFolders as $allowedFolder) {
                    $dir = $this->targetDirectory->getDirectoryReadByPath($allowedFolder);
                    if ($dir->isExist($tmpName)) {
                        $isValid = true;
                        break;
                    }
                }

                foreach ($disallowedFolders as $disallowedFolder) {
                    $dir = $this->targetDirectory->getDirectoryReadByPath($disallowedFolder);
                    if ($dir->isExist($tmpName)) {
                        $isValid = false;
                        break;
                    }
                }
            }
        }

        if (!$isValid) {
            throw new \InvalidArgumentException(
                __('Invalid parameter given. A valid $fileId[tmp_name] is expected.')
            );
        }
    }

    /**
     * Create destination folder
     *
     * @param string $destinationFolder
     * @return Uploader
     * @throws FileSystemException
     */
    private function createDestinationFolder(string $destinationFolder)
    {
        if (!$destinationFolder) {
            return $this;
        }

        if (substr($destinationFolder, -1) == '/') {
            $destinationFolder = substr($destinationFolder, 0, -1);
        }

        $rootDirectory = $this->getTargetDirectory()->getDirectoryWrite(DirectoryList::ROOT);

        if (!$rootDirectory->isDirectory($destinationFolder)) {
            $result = $rootDirectory->getDriver()->createDirectory($destinationFolder);
            if (!$result) {
                throw new FileSystemException(__('Unable to create directory %1.', $destinationFolder));
            }
        }

        return $this;
    }

    /**
     * Get new file name if the same is already exists
     *
     * @param string $destinationFile
     * @return string
     */
    public static function getNewFileName($destinationFile)
    {
        /** @var Filesystem $fileSystem */
        $fileSystem = ObjectManager::getInstance()->get(Filesystem::class);
        $local = $fileSystem->getDirectoryRead(DirectoryList::ROOT);
        /** @var TargetDirectory $targetDirectory */
        $targetDirectory = ObjectManager::getInstance()->get(TargetDirectory::class);
        $remote = $targetDirectory->getDirectoryRead(DirectoryList::ROOT);

        $fileExists = function ($path) use ($local, $remote) {
            return $local->isExist($path) || $remote->isExist($path);
        };

        $fileInfo = pathinfo($destinationFile);
        $index = 1;
        while ($fileExists($fileInfo['dirname'] . '/' . $fileInfo['basename'])) {
            $fileInfo['basename'] = $fileInfo['filename'] . '_' . ($index++);
            $fileInfo['basename'] .= isset($fileInfo['extension']) ? '.' . $fileInfo['extension'] : '';
        }

        return $fileInfo['basename'];
    }

    /**
     * Get dispersion path
     *
     * @param string $fileName
     * @return string
     * @deprecated 101.0.4
     */
    public static function getDispretionPath($fileName)
    {
        return self::getDispersionPath($fileName);
    }

    /**
     * Get dispersion path
     *
     * @param string $fileName
     * @return string
     * @since 101.0.4
     */
    public static function getDispersionPath($fileName)
    {
        $char = 0;
        $dispersionPath = '';
        while ($char < 2 && ($fileName && $char < strlen($fileName))) {
            if (empty($dispersionPath)) {
                $dispersionPath = '/' . ('.' == $fileName[$char] ? '_' : $fileName[$char]);
            } else {
                $dispersionPath = self::_addDirSeparator(
                    $dispersionPath
                ) . ('.' == $fileName[$char] ? '_' : $fileName[$char]);
            }
            $char++;
        }
        return $dispersionPath;
    }

    /**
     * Get driver for file
     *
     * @deprecated
     * @return DriverInterface
     */
    private function getFileDriver(): DriverInterface
    {
        if (!$this->fileDriver) {
            $this->driverPool = $this->driverPool ?: ObjectManager::getInstance()->get(DriverPool::class);
            $this->fileDriver = $this->driverPool->getDriver(DriverPool::FILE);
        }

        return $this->fileDriver;
    }
}
