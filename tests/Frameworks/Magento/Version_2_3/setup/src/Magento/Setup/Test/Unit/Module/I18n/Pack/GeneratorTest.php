<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Setup\Test\Unit\Module\I18n\Pack;

/**
 * Generator test
 */
class GeneratorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Setup\Module\I18n\Dictionary\Loader\FileInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $dictionaryLoaderMock;

    /**
     * @var \Magento\Setup\Module\I18n\Pack\WriterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $packWriterMock;

    /**
     * @var \Magento\Setup\Module\I18n\Factory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $factoryMock;

    /**
     * @var \Magento\Setup\Module\I18n\Dictionary|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $dictionaryMock;

    /**
     * @var \Magento\Setup\Module\I18n\Pack\Generator
     */
    protected $_generator;

    protected function setUp(): void
    {
        $this->dictionaryLoaderMock =
            $this->createMock(\Magento\Setup\Module\I18n\Dictionary\Loader\FileInterface::class);
        $this->packWriterMock = $this->createMock(\Magento\Setup\Module\I18n\Pack\WriterInterface::class);
        $this->factoryMock = $this->createMock(\Magento\Setup\Module\I18n\Factory::class);
        $this->dictionaryMock = $this->createMock(\Magento\Setup\Module\I18n\Dictionary::class);

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->_generator = $objectManagerHelper->getObject(
            \Magento\Setup\Module\I18n\Pack\Generator::class,
            [
                'dictionaryLoader' => $this->dictionaryLoaderMock,
                'packWriter' => $this->packWriterMock,
                'factory' => $this->factoryMock
            ]
        );
    }

    public function testGenerate()
    {
        $dictionaryPath = 'dictionary_path';
        $localeString = 'locale';
        $mode = 'mode';
        $allowDuplicates = true;
        $localeMock = $this->createMock(\Magento\Setup\Module\I18n\Locale::class);

        $phrases = [$this->createMock(\Magento\Setup\Module\I18n\Dictionary\Phrase::class)];
        $this->dictionaryMock->expects($this->once())
            ->method('getPhrases')
            ->willReturn([$phrases]);

        $this->factoryMock->expects($this->once())
            ->method('createLocale')
            ->with($localeString)
            ->willReturn($localeMock);
        $this->dictionaryLoaderMock->expects($this->once())
            ->method('load')
            ->with($dictionaryPath)
            ->willReturn($this->dictionaryMock);
        $this->packWriterMock->expects($this->once())
            ->method('writeDictionary')
            ->with($this->dictionaryMock, $localeMock, $mode);

        $this->_generator->generate($dictionaryPath, $localeString, $mode, $allowDuplicates);
    }

    /**
     */
    public function testGenerateEmptyFile()
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('No phrases have been found by the specified path.');

        $dictionaryPath = 'dictionary_path';
        $localeString = 'locale';
        $mode = 'mode';
        $allowDuplicates = true;
        $localeMock = $this->createMock(\Magento\Setup\Module\I18n\Locale::class);

        $this->factoryMock->expects($this->once())
            ->method('createLocale')
            ->with($localeString)
            ->willReturn($localeMock);
        $this->dictionaryLoaderMock->expects($this->once())
            ->method('load')
            ->with($dictionaryPath)
            ->willReturn($this->dictionaryMock);
        $this->dictionaryMock->expects($this->once())
            ->method('getPhrases')
            ->willReturn([]);

        $this->_generator->generate($dictionaryPath, $localeString, $mode, $allowDuplicates);
    }

    public function testGenerateWithNotAllowedDuplicatesAndDuplicatesExist()
    {
        $error = "Duplicated translation is found, but it is not allowed.\n"
            . "The phrase \"phrase1\" is translated in 1 places.\n"
            . "The phrase \"phrase2\" is translated in 1 places.\n";
        $this->expectException('\RuntimeException');
        $this->expectExceptionMessage($error);

        $allowDuplicates = false;

        $phraseFirstMock = $this->createMock(\Magento\Setup\Module\I18n\Dictionary\Phrase::class);
        $phraseFirstMock->expects($this->once())->method('getPhrase')->willReturn('phrase1');
        $phraseSecondMock = $this->createMock(\Magento\Setup\Module\I18n\Dictionary\Phrase::class);
        $phraseSecondMock->expects($this->once())->method('getPhrase')->willReturn('phrase2');

        $this->dictionaryLoaderMock->expects($this->any())
            ->method('load')
            ->willReturn($this->dictionaryMock);
        $phrases = [$this->createMock(\Magento\Setup\Module\I18n\Dictionary\Phrase::class)];
        $this->dictionaryMock->expects($this->once())
            ->method('getPhrases')
            ->willReturn([$phrases]);
        $this->dictionaryMock->expects($this->once())
            ->method('getDuplicates')
            ->willReturn([[$phraseFirstMock], [$phraseSecondMock]]);

        $this->_generator->generate('dictionary_path', 'locale', 'mode', $allowDuplicates);
    }
}
