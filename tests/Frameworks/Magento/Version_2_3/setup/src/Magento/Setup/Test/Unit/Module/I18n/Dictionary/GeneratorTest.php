<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Setup\Test\Unit\Module\I18n\Dictionary;

/**
 * Generator test
 */
class GeneratorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Setup\Module\I18n\Parser\Parser|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $parserMock;

    /**
     * @var \Magento\Setup\Module\I18n\Parser\Contextual|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $contextualParserMock;

    /**
     * @var \Magento\Setup\Module\I18n\Factory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $factoryMock;

    /**
     * @var \Magento\Setup\Module\I18n\Dictionary\WriterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $writerMock;

    /**
     * @var \Magento\Setup\Module\I18n\Dictionary\Generator
     */
    protected $generator;

    /**
     * @var \Magento\Setup\Module\I18n\Dictionary\Options\ResolverFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $optionsResolverFactory;

    protected function setUp(): void
    {
        $this->parserMock = $this->createMock(\Magento\Setup\Module\I18n\Parser\Parser::class);
        $this->contextualParserMock = $this->createMock(\Magento\Setup\Module\I18n\Parser\Contextual::class);
        $this->writerMock = $this->createMock(\Magento\Setup\Module\I18n\Dictionary\WriterInterface::class);
        $this->factoryMock = $this->createMock(\Magento\Setup\Module\I18n\Factory::class);
        $this->factoryMock->expects($this->any())
            ->method('createDictionaryWriter')
            ->willReturn($this->writerMock);

        $this->optionsResolverFactory =
            $this->createMock(\Magento\Setup\Module\I18n\Dictionary\Options\ResolverFactory::class);

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->generator = $objectManagerHelper->getObject(
            \Magento\Setup\Module\I18n\Dictionary\Generator::class,
            [
                'parser' => $this->parserMock,
                'contextualParser' => $this->contextualParserMock,
                'factory' => $this->factoryMock,
                'optionsResolver' => $this->optionsResolverFactory
            ]
        );
    }

    public function testCreatingDictionaryWriter()
    {
        $outputFilename = 'test';

        $phrase = $this->createMock(\Magento\Setup\Module\I18n\Dictionary\Phrase::class);
        $this->factoryMock->expects($this->once())
            ->method('createDictionaryWriter')
            ->with($outputFilename)
            ->willReturnSelf();
        $this->parserMock->expects($this->any())->method('getPhrases')->willReturn([$phrase]);
        $options = [];
        $optionResolver = $this->createMock(\Magento\Setup\Module\I18n\Dictionary\Options\Resolver::class);
        $optionResolver->expects($this->once())
            ->method('getOptions')
            ->willReturn($options);
        $this->optionsResolverFactory->expects($this->once())
            ->method('create')
            ->with($this->equalTo(''), $this->equalTo(false))
            ->willReturn($optionResolver);
        $this->generator->generate('', $outputFilename);
        $property = new \ReflectionProperty($this->generator, 'writer');
        $property->setAccessible(true);
        $this->assertNull($property->getValue($this->generator));
    }

    public function testUsingRightParserWhileWithoutContextParsing()
    {
        $baseDir = 'right_parser';
        $outputFilename = 'file.csv';
        $filesOptions = ['file1', 'file2'];
        $optionResolver =
            $this->createMock(\Magento\Setup\Module\I18n\Dictionary\Options\Resolver::class);
        $optionResolver->expects($this->once())
            ->method('getOptions')
            ->willReturn($filesOptions);

        $this->factoryMock->expects($this->once())
            ->method('createDictionaryWriter')
            ->with($outputFilename)
            ->willReturnSelf();

        $this->optionsResolverFactory->expects($this->once())
            ->method('create')
            ->with($this->equalTo($baseDir), $this->equalTo(false))
            ->willReturn($optionResolver);
        $this->parserMock->expects($this->once())->method('parse')->with($filesOptions);
        $phrase = $this->createMock(\Magento\Setup\Module\I18n\Dictionary\Phrase::class);
        $this->parserMock->expects($this->once())->method('getPhrases')->willReturn([$phrase]);
        $this->generator->generate($baseDir, $outputFilename);
    }

    public function testUsingRightParserWhileWithContextParsing()
    {
        $baseDir = 'right_parser2';
        $outputFilename = 'file.csv';
        $filesOptions = ['file1', 'file2'];
        $optionResolver =
            $this->createMock(\Magento\Setup\Module\I18n\Dictionary\Options\Resolver::class);
        $optionResolver->expects($this->once())
            ->method('getOptions')
            ->willReturn($filesOptions);
        $this->optionsResolverFactory->expects($this->once())
            ->method('create')
            ->with($this->equalTo($baseDir), $this->equalTo(true))
            ->willReturn($optionResolver);

        $this->contextualParserMock->expects($this->once())->method('parse')->with($filesOptions);
        $phrase = $this->createMock(\Magento\Setup\Module\I18n\Dictionary\Phrase::class);
        $this->contextualParserMock->expects($this->once())->method('getPhrases')->willReturn([$phrase]);

        $this->factoryMock->expects($this->once())
            ->method('createDictionaryWriter')
            ->with($outputFilename)
            ->willReturnSelf();

        $this->generator->generate($baseDir, $outputFilename, true);
    }

    public function testWritingPhrases()
    {
        $baseDir = 'WritingPhrases';
        $filesOptions = ['file1', 'file2'];
        $optionResolver =
            $this->createMock(\Magento\Setup\Module\I18n\Dictionary\Options\Resolver::class);
        $optionResolver->expects($this->once())
            ->method('getOptions')
            ->willReturn($filesOptions);
        $this->optionsResolverFactory->expects($this->once())
            ->method('create')
            ->with($this->equalTo($baseDir), $this->equalTo(false))
            ->willReturn($optionResolver);

        $phrases = [
            $this->createMock(\Magento\Setup\Module\I18n\Dictionary\Phrase::class),
            $this->createMock(\Magento\Setup\Module\I18n\Dictionary\Phrase::class),
        ];

        $this->parserMock->expects($this->once())->method('getPhrases')->willReturn($phrases);
        $this->writerMock->expects($this->at(0))->method('write')->with($phrases[0]);
        $this->writerMock->expects($this->at(1))->method('write')->with($phrases[1]);

        $this->generator->generate($baseDir, 'file.csv');
    }

    /**
     */
    public function testGenerateWithNoPhrases()
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('No phrases found in the specified dictionary file.');

        $baseDir = 'no_phrases';
        $outputFilename = 'no_file.csv';
        $filesOptions = ['file1', 'file2'];
        $optionResolver =
            $this->createMock(\Magento\Setup\Module\I18n\Dictionary\Options\Resolver::class);
        $optionResolver->expects($this->once())
            ->method('getOptions')
            ->willReturn($filesOptions);
        $this->optionsResolverFactory->expects($this->once())
            ->method('create')
            ->with($this->equalTo($baseDir), $this->equalTo(true))
            ->willReturn($optionResolver);

        $this->contextualParserMock->expects($this->once())->method('parse')->with($filesOptions);
        $this->contextualParserMock->expects($this->once())->method('getPhrases')->willReturn([]);
        $this->generator->generate($baseDir, $outputFilename, true);
    }
}
