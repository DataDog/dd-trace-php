<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Cms\Controller\Adminhtml\Wysiwyg\Images;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\Json as JsonResponse;
use Magento\Framework\App\Response\HttpFactory as ResponseFactory;
use Magento\Framework\App\Response\Http as Response;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Test for \Magento\Cms\Controller\Adminhtml\Wysiwyg\Images\Upload class.
 *
 * @magentoAppArea adminhtml
 */
class UploadTest extends \PHPUnit\Framework\TestCase
{
    private const MEDIA_GALLERY_IMAGE_FOLDERS_CONFIG_PATH
        = 'system/media_storage_configuration/allowed_resources/media_gallery_image_folders';

    /**
     * @var array
     */
    private $origConfigValue;

    /**
     * @var \Magento\Cms\Controller\Adminhtml\Wysiwyg\Images\Upload
     */
    private $model;

    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface
     */
    private $mediaDirectory;

    /**
     * @var string
     */
    private $fullDirectoryPath;

    /**
     * @var string
     */
    private $fullExcludedDirectoryPath;

    /**
     * @var string
     */
    private $fileName = 'magento_small_image.jpg';

    /**
     * @var \Magento\Framework\Filesystem
     */
    private $filesystem;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var HttpFactory
     */
    private $responseFactory;

    /**
     * @var  \Magento\Cms\Helper\Wysiwyg\Images
     */
    private $imagesHelper;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $directoryName = 'testDir';
        $excludedDirName = 'downloadable';
        $this->filesystem = $this->objectManager->get(\Magento\Framework\Filesystem::class);
        /** @var \Magento\Cms\Helper\Wysiwyg\Images $imagesHelper */
        $this->imagesHelper = $this->objectManager->get(\Magento\Cms\Helper\Wysiwyg\Images::class);
        $this->mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->fullDirectoryPath = rtrim($this->imagesHelper->getStorageRoot(), '/')
            . DIRECTORY_SEPARATOR . $directoryName;
        $this->fullExcludedDirectoryPath = $this->imagesHelper->getStorageRoot()
            . DIRECTORY_SEPARATOR . $excludedDirName;
        $this->mediaDirectory->create($this->mediaDirectory->getRelativePath($this->fullDirectoryPath));
        $this->responseFactory = $this->objectManager->get(ResponseFactory::class);
        $this->model = $this->objectManager->get(\Magento\Cms\Controller\Adminhtml\Wysiwyg\Images\Upload::class);
        $fixtureDir = realpath(__DIR__ . '/../../../../../Catalog/_files');
        $tmpFile = $this->filesystem->getDirectoryRead(DirectoryList::PUB)->getAbsolutePath() . $this->fileName;
        copy($fixtureDir . DIRECTORY_SEPARATOR . $this->fileName, $tmpFile);
        $_FILES = [
            'image' => [
                'name' => $this->fileName,
                'type' => 'image/png',
                'tmp_name' => $tmpFile,
                'error' => 0,
                'size' => filesize($fixtureDir),
            ],
        ];
        $config = $this->objectManager->get(ScopeConfigInterface::class);
        $this->origConfigValue = $config->getValue(
            self::MEDIA_GALLERY_IMAGE_FOLDERS_CONFIG_PATH,
            'default'
        );
        $scopeConfig = $this->objectManager->get(\Magento\Framework\App\Config\MutableScopeConfigInterface::class);
        $scopeConfig->setValue(
            self::MEDIA_GALLERY_IMAGE_FOLDERS_CONFIG_PATH,
            array_merge($this->origConfigValue, ['testDir']),
        );
    }

    protected function tearDown(): void
    {
        $directoryName = 'testDir';
        $this->mediaDirectory->delete(
            $this->mediaDirectory->getRelativePath($this->imagesHelper->getStorageRoot() . '/' . $directoryName)
        );
        $scopeConfig = $this->objectManager->get(\Magento\Framework\App\Config\MutableScopeConfigInterface::class);
        $scopeConfig->setValue(
            self::MEDIA_GALLERY_IMAGE_FOLDERS_CONFIG_PATH,
            $this->origConfigValue
        );
    }

    /**
     * Execute method with correct directory path and file name to check that file can be uploaded to the directory
     * located under WYSIWYG media.
     *
     * @return void
     * @magentoAppIsolation enabled
     */
    public function testExecute()
    {
        $this->model->getRequest()->setParams(['type' => 'image/png']);
        $this->model->getRequest()->setMethod('POST');
        $this->model->getStorage()->getSession()->setCurrentPath($this->fullDirectoryPath);
        /** @var JsonResponse $jsonResponse */
        $jsonResponse = $this->model->execute();
        /** @var Response $response */
        $jsonResponse->renderResult($response = $this->responseFactory->create());
        $data = json_decode($response->getBody(), true);

        $this->assertTrue(
            $this->mediaDirectory->isExist(
                $this->mediaDirectory->getRelativePath(
                    $this->fullDirectoryPath . DIRECTORY_SEPARATOR . $this->fileName
                )
            )
        );
        //Asserting that response contains only data needed by clients.
        $keys = ['name', 'type', 'error', 'size', 'file'];
        sort($keys);
        $dataKeys = array_keys($data);
        sort($dataKeys);
        $this->assertEquals($keys, $dataKeys);
    }

    /**
     * Execute method with excluded directory path and file name to check that file can't be uploaded.
     *
     * @return void
     * @magentoAppIsolation enabled
     */
    public function testExecuteWithExcludedDirectory()
    {
        $expectedError = 'We can\'t upload the file to the current folder right now. Please try another folder.';
        $this->model->getRequest()->setParams(['type' => 'image/png']);
        $this->model->getRequest()->setMethod('POST');
        $this->model->getStorage()->getSession()->setCurrentPath($this->fullExcludedDirectoryPath);
        /** @var JsonResponse $jsonResponse */
        $jsonResponse = $this->model->execute();
        /** @var Response $response */
        $jsonResponse->renderResult($response = $this->responseFactory->create());
        $data = json_decode($response->getBody(), true);

        $this->assertEquals($expectedError, $data['error']);
        $this->assertFalse(
            $this->mediaDirectory->isExist(
                $this->mediaDirectory->getRelativePath(
                    $this->fullExcludedDirectoryPath . DIRECTORY_SEPARATOR . $this->fileName
                )
            )
        );
    }

    /**
     * Execute method with correct directory path and file name to check that file can be uploaded to the directory
     * located under linked folder.
     *
     * @return void
     * @magentoDataFixture Magento/Cms/_files/linked_media.php
     */
    public function testExecuteWithLinkedMedia()
    {
        if (!$this->mediaDirectory->getDriver() instanceof File) {
            self::markTestSkipped('Remote storages like AWS S3 doesn\'t support symlinks');
        }

        $directoryName = 'linked_media';
        $fullDirectoryPath = $this->filesystem->getDirectoryRead(DirectoryList::PUB)
                ->getAbsolutePath() . $directoryName;
        $wysiwygDir = $this->mediaDirectory->getAbsolutePath() . '/wysiwyg';
        $this->model->getRequest()->setParams(['type' => 'image/png']);
        $this->model->getStorage()->getSession()->setCurrentPath($wysiwygDir);
        $this->model->execute();
        $this->assertTrue(is_file($fullDirectoryPath . DIRECTORY_SEPARATOR . $this->fileName));
    }

    /**
     * Execute method with traversal directory path to check that there is no ability to create file not
     * under media directory.
     *
     * @return void
     */
    public function testExecuteWithWrongPath()
    {
        $dirPath = '/../../../etc/';
        $this->model->getRequest()->setParams(['type' => 'image/png']);
        $this->model->getStorage()->getSession()->setCurrentPath($dirPath);
        $this->model->execute();

        $this->assertFileDoesNotExist(
            $this->fullDirectoryPath . $dirPath . $this->fileName
        );
    }

    /**
     * Execute method with traversal file path to check that there is no ability to create file not
     * under media directory.
     *
     * @return void
     */
    public function testExecuteWithWrongFileName()
    {
        $newFilename = '/../../../../etc/new_file.png';
        $_FILES['image']['name'] = $newFilename;
        $_FILES['image']['tmp_name'] = __DIR__ . DIRECTORY_SEPARATOR . $this->fileName;
        $this->model->getRequest()->setParams(['type' => 'image/png']);
        $this->model->getStorage()->getSession()->setCurrentPath($this->fullDirectoryPath);
        $this->model->execute();

        $this->assertFileDoesNotExist($this->fullDirectoryPath . $newFilename);
    }

    /**
     * @inheritdoc
     */
    public static function tearDownAfterClass(): void
    {
        $filesystem = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(\Magento\Framework\Filesystem::class);
        /** @var \Magento\Framework\Filesystem\Directory\WriteInterface $directory */
        $directory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        if ($directory->isExist('wysiwyg')) {
            $directory->delete('wysiwyg');
        }
    }
}
