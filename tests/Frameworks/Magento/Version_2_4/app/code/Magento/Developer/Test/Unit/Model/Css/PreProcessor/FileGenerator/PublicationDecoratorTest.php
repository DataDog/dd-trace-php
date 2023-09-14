<?php declare(strict_types=1);
/***
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Developer\Test\Unit\Model\Css\PreProcessor\FileGenerator;

use Magento\Developer\Model\Css\PreProcessor\FileGenerator\PublicationDecorator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\App\View\Asset\Publisher;
use Magento\Framework\Css\PreProcessor\File\Temporary;
use Magento\Framework\Css\PreProcessor\Instruction\Import;
use Magento\Framework\Filesystem;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\Asset\File;
use Magento\Framework\View\Asset\LocalInterface;
use Magento\Framework\View\Asset\Repository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PublicationDecoratorTest extends TestCase
{
    /**
     * @var PublicationDecorator
     */
    private $model;

    /**
     * @var Filesystem|MockObject
     */
    private $filesystemMock;

    /**
     * @var Temporary|MockObject
     */
    private $temporaryFileMock;

    /**
     * @var Publisher|MockObject
     */
    private $publisherMock;

    /**
     * @var Repository|MockObject
     */
    private $assetRepositoryMock;

    /**
     * @var File|MockObject
     */
    private $relatedAssetMock;

    /**
     * @var Import|MockObject
     */
    private $importGeneratorMock;

    /**
     * @var LocalInterface|MockObject
     */
    private $localAssetMock;

    /**
     * @var ScopeConfigInterface|MockObject
     */
    private $scopeConfigMock;

    /**
     * @var State|MockObject
     */
    private $stateMock;

    protected function setUp(): void
    {
        $this->filesystemMock = $this->getMockBuilder(Filesystem::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->temporaryFileMock = $this->getMockBuilder(Temporary::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->publisherMock = $this->getMockBuilder(Publisher::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->assetRepositoryMock = $this->getMockBuilder(Repository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->relatedAssetMock = $this->getMockBuilder(File::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->importGeneratorMock = $this->getMockBuilder(Import::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->localAssetMock = $this->getMockBuilder(LocalInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->scopeConfigMock = $this->getMockBuilder(ScopeConfigInterface::class)
            ->getMockForAbstractClass();
        $this->stateMock = $this->getMockBuilder(State::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = (new ObjectManager($this))->getObject(PublicationDecorator::class, [
            'assetRepository' => $this->assetRepositoryMock,
            'temporaryFile' => $this->temporaryFileMock,
            'assetPublisher' => $this->publisherMock,
            'scopeConfig' => $this->scopeConfigMock,
            'state' => $this->stateMock,
            'hasRelatedPublishing' => true,
        ]);
    }

    /**
     * Calls generate method to access protected method generateRelatedFile
     */
    public function testGenerateRelatedFile()
    {
        $relatedFileId = 'file_id';
        $relatedFiles = [[$relatedFileId, $this->localAssetMock]];

        $this->importGeneratorMock->expects($this->any())
            ->method('getRelatedFiles')
            ->willReturnOnConsecutiveCalls($relatedFiles, []);
        $this->assetRepositoryMock->expects($this->any())
            ->method('createRelated')
            ->willReturn($this->relatedAssetMock);
        $this->publisherMock->expects($this->once())
            ->method('publish')
            ->with($this->relatedAssetMock);

        $this->model->generate($this->importGeneratorMock);
    }
}
